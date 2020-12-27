<?php
/**
 * Main plugin class.
 *
 * @package Webmention_Comments
 */

namespace Webmention_Comments;

/**
 * Main plugin class.
 *
 * @since 0.2
 */
class Webmention_Comments {
	/**
	 * Single class instance.
	 *
	 * @since 0.9
	 *
	 * @var Webmention_Comments Single class instance.
	 */
	private static $instance;

	/**
	 * Database table version.
	 *
	 * @since 0.3
	 *
	 * @var string $db_version Database table version, in case we ever want to upgrade its structure.
	 */
	private $db_version = '1.0';

	/**
	 * Returns the single instance of this class.
	 *
	 * @since 0.9
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Class constructor.
	 *
	 * @since 0.2
	 */
	private function __construct() {
		// Private constructor.
	}

	/**
	 * Registers hook callbacks.
	 *
	 * @since 0.9
	 */
	public function register() {
		// Deactivation and uninstall hooks.
		register_deactivation_hook( dirname( dirname( __FILE__ ) ) . '/webmention-comments.php', array( $this, 'deactivate' ) );
		register_uninstall_hook( dirname( dirname( __FILE__ ) ) . '/webmention-comments.php', array( __CLASS__, 'uninstall' ) );

		// Schedule WP-Cron job.
		add_action( 'init', array( $this, 'activate' ) );

		// Allow plugin i18n.
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

		// Register a new REST API route (it's that easy).
		add_action(
			'rest_api_init',
			function() {
				register_rest_route(
					'webmention-comments/v1',
					'/create',
					array(
						'methods'             => 'POST',
						'callback'            => array( $this, 'store_webmention' ),
						'permission_callback' => '__return_true',
					)
				);
			}
		);

		add_action( 'process_webmentions', array( $this, 'process_webmentions' ) );
		add_action( 'transition_post_status', array( $this, 'schedule_webmention' ), 10, 3 );

		add_action( 'webmention_comments_send', array( $this, 'send_webmention' ) );

		add_action( 'wp_head', array( $this, 'webmention_link' ) );
	}

	/**
	 * Stores incoming webmentions and that's about it.
	 *
	 * @param WP_REST_Request $request WP REST API request.
	 *
	 * @return WP_REST_Response WP REST API response.
	 *
	 * @since 0.2
	 */
	public function store_webmention( $request ) {
		// Verify source nor target are invalid URLs.
		if ( empty( $request['source'] ) || ! wp_http_validate_url( $request['source'] ) || empty( $request['target'] ) || ! wp_http_validate_url( $request['target'] ) ) {
			return new \WP_Error( 'invalid_request', 'Invalid source or target', array( 'status' => 400 ) );
		}

		global $wp_rewrite;

		// Get the target post's slug, sans permalink front.
		$slug = trim( basename( wp_parse_url( $request['target'], PHP_URL_PATH ) ), '/' );

		$supported_post_types = (array) apply_filters( 'webmention_comments_post_types', array( 'post' ) );

		// Fetch the post.
		$post = get_page_by_path( $slug, OBJECT, $supported_post_types );
		$post = apply_filters( 'webmention_comments_post', $post, $request['target'], $supported_post_types );

		if ( empty( $post ) || 'publish' !== get_post_status( $post->ID ) ) {
			// Not found.
			return new \WP_Error( 'not_found', 'Not found', array( 'status' => 404 ) );
		}

		// Set sender's IP address.
		$ip = ( ! empty( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '' ); // phpcs:ignore
		$ip = preg_replace( '/[^0-9a-fA-F:., ]/', '', apply_filters( 'webmention_comments_sender_ip', $ip, $request ) );

		global $wpdb;

		// Insert webmention into database.
		$num_rows = $wpdb->insert( // phpcs:ignore
			$wpdb->prefix . 'webmention_comments',
			array(
				'source'     => esc_url_raw( $request['source'] ),
				'post_id'    => $post->ID,
				'ip'         => $ip,
				'status'     => 'draft',
				'created_at' => current_time( 'mysql' ),
			)
		);

		if ( false !== $num_rows ) {
			// Create an empty REST response and add an 'Accepted' status code.
			$response = new \WP_REST_Response( array() );
			$response->set_status( 202 );

			return $response;
		}

		return new \WP_Error( 'invalid_request', 'Invalid source or target', array( 'status' => 400 ) );
	}

	/**
	 * Processes queued webmentions. Typically triggered by WP Cron.
	 *
	 * @since 0.3
	 */
	public function process_webmentions() {
		global $wpdb;

		$table_name  = $wpdb->prefix . 'webmention_comments';
		$webmentions = $wpdb->get_results( "SELECT id, source, post_id, ip, created_at FROM $table_name WHERE status = 'draft' LIMIT 5" ); // phpcs:ignore

		if ( empty( $webmentions ) || ! is_array( $webmentions ) ) {
			// Empty queue.
			return;
		}

		foreach ( $webmentions as $webmention ) {
			// Fetch source HTML.
			$response = wp_safe_remote_get( esc_url_raw( $webmention->source ) );

			if ( is_wp_error( $response ) ) {
				// Something went wrong.
				error_log( $response->get_error_message() ); // phpcs:ignore
				continue;
			}

			$html = wp_remote_retrieve_body( $response );

			if ( false === stripos( $html, get_permalink( $webmention->post_id ) ) ) {
				// Target URL not (or no longer) mentioned by source. Mark webmention as processed.
				$wpdb->update( // phpcs:ignore
					$table_name,
					array( 'status' => 'invalid' ),
					array( 'id' => $webmention->id ),
					array( '%s' ),
					array( '%d' )
				);

				// Skip to next webmention.
				continue;
			}

			// Grab source domain.
			$host = wp_parse_url( $webmention->source, PHP_URL_HOST );

			// Some defaults.
			$commentdata = array(
				'comment_post_ID'      => apply_filters( 'webmention_comments_post_id', $webmention->post_id ),
				'comment_author'       => $host,
				'comment_author_email' => 'someone@example.org',
				'comment_author_url'   => esc_url_raw( wp_parse_url( $webmention->source, PHP_URL_SCHEME ) . '://' . $host ),
				'comment_author_IP'    => $webmention->ip,
				'comment_content'      => __( '&hellip; commented on this.', 'webmention-comments' ),
				'comment_parent'       => 0,
				'user_id'              => 0,
				'comment_date'         => $webmention->created_at,
				'comment_date_gmt'     => get_gmt_from_date( $webmention->created_at ),
				'comment_type'         => '',
				'comment_meta'         => array(
					'webmention_source' => esc_url_raw( $webmention->source ),
				),
			);

			// Search source for supported microformats, and update
			// `$commentdata` accordingly.
			$this->parse_microformats( $commentdata, $html, $webmention->source, get_permalink( $webmention->post_id ) );

			// Disable comment flooding check.
			remove_action( 'check_comment_flood', 'check_comment_flood_db' );

			// Insert new comment.
			$comment_id = wp_new_comment( $commentdata, true );

			// Default status. "Complete" means "done processing," rather than
			// 'success'.
			$status = 'complete';

			if ( is_wp_error( $comment_id ) ) {
				// For troubleshooting.
				error_log( print_r( $comment_id, true ) ); // phpcs:ignore

				if ( in_array( 'comment_duplicate', $comment_id->get_error_codes(), true ) ) {
					// Log if deemed duplicate. Could come in useful if we ever
					// wanna support "updated" webmentions.
					$status = 'duplicate';
				}
			}

			// Mark webmention as processed.
			$wpdb->update( // phpcs:ignore
				$table_name,
				array( 'status' => $status ),
				array( 'id' => $webmention->id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Schedules the sending of webmentions, if enabled.
	 *
	 * Scans for outgoing links, but leaves fetching Webmention endpoints to the
	 * callback function queued in the background.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 *
	 * @since 0.8
	 */
	public function schedule_webmention( $new_status, $old_status, $post ) {
		if ( wp_is_post_revision( $post->ID ) || wp_is_post_autosave( $post->ID ) ) {
			// Prevent double posting.
			return;
		}

		if ( defined( 'OUTGOING_WEBMENTIONS' ) && ! OUTGOING_WEBMENTIONS ) {
			// Disabled.
			return;
		}

		if ( 'publish' !== $new_status ) {
			// Do not send webmention on delete, for now.
			return;
		}

		$supported_post_types = (array) apply_filters( 'webmention_comments_post_types', array( 'post' ) );

		if ( ! in_array( $post->post_type, $supported_post_types, true ) ) {
			return;
		}

		if ( '' !== get_post_meta( $post->ID, '_webmention_sent', true ) ) {
			return;
		}

		// Fetch our post's HTML.
		$html = apply_filters( 'the_content', $post->post_content );

		// Scan it for outgoing links.
		$urls = $this->find_outgoing_links( $html );

		if ( empty( $urls ) || ! is_array( $urls ) ) {
			// Nothing to do. Bail.
			return;
		}

		// Schedule the actual looking for Webmention endpoints (and, if
		// applicable, sending out webmentions) in the background.
		wp_schedule_single_event( time() + wp_rand( 0, 300 ), 'webmention_comments_send', array( $post->ID ) );
	}

	/**
	 * Attempts to send webmentions to Webmention-compatible URLs mentioned in
	 * a post.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @since 0.5
	 */
	public function send_webmention( $post_id ) {
		$post = get_post( $post_id );

		if ( 'publish' !== $post->post_status ) {
			// Do not send webmention on delete, for now.
			return;
		}

		$supported_post_types = apply_filters( 'webmention_comments_post_types', array( 'post' ) );

		if ( ! in_array( $post->post_type, $supported_post_types, true ) ) {
			// This post type doesn't support Webmention.
			return;
		}

		// Fetch our post's HTML.
		$html = apply_filters( 'the_content', $post->post_content );

		// Scan it for outgoing links, again, as things might have changed.
		$urls = $this->find_outgoing_links( $html );

		if ( empty( $urls ) || ! is_array( $urls ) ) {
			// One or more links must've been removed. Nothing to do. Bail.
			return;
		}

		// Fetch whatever Webmention-related stats we've got for this post.
		$webmention = get_post_meta( $post->ID, '_webmention', true );

		if ( empty( $webmention ) || ! is_array( $webmention ) ) {
			// Ensure `$webmention` is an array.
			$webmention = array();
		}

		foreach ( $urls as $url ) {
			// Try to find a Webmention endpoint.
			// phpcs:ignore
			$endpoint = $this->webmention_discover_endpoint( $url );

			if ( empty( $endpoint ) ) {
				// Skip.
				continue;
			}

			if ( false === wp_http_validate_url( $endpoint ) ) {
				// Not a valid URL.
				continue;
			}

			if ( ! empty( $webmention[ esc_url_raw( $endpoint ) ]['sent'] ) ) {
				// Succesfully sent before. Skip. Note that this complicates
				// resending after an update quite a bit.
				continue;
			}

			$retries = ( ! empty( $webmention[ esc_url_raw( $endpoint ) ]['retries'] ) ? (int) $webmention[ esc_url_raw( $endpoint ) ]['retries'] : 0 );

			if ( $retries >= 3 ) {
				// Stop here.
				error_log( 'Sending webmention to ' . esc_url_raw( $url ) . ' failed 3 times before. Not trying again.' ); // phpcs:ignore
				continue;
			}

			// Send the webmention.
			$response = wp_remote_post(
				esc_url_raw( $endpoint ),
				array(
					'body'    => array(
						'source' => get_permalink( $post->ID ),
						'target' => $url,
					),
					'timeout' => 15, // The default of 5 seconds leads to time-outs too often.
				)
			);

			if ( is_wp_error( $response ) ) {
				// Something went wrong.
				error_log( 'Error trying to send a webmention to ' . esc_url_raw( $endpoint ) . ': ' . $response->get_error_message() ); // phpcs:ignore

				$webmention[ esc_url_raw( $endpoint ) ]['retries'] = $retries + 1;
				update_post_meta( $post->ID, '_webmention', $webmention );

				// Schedule a retry in 5 to 15 minutes.
				wp_schedule_single_event( time() + wp_rand( 300, 900 ), 'webmention_comments_send', array( $post->ID ) );

				continue;
			}

			// Success! (Or rather, no immediate error.) Store timestamp.
			$webmention[ esc_url_raw( $endpoint ) ]['sent'] = current_time( 'mysql' );
			update_post_meta( $post->ID, '_webmention', $webmention );

			error_log( 'Sent webmention to ' . esc_url_raw( $endpoint ) . '. Response code: ' . wp_remote_retrieve_response_code( $response ) . '.' ); // phpcs:ignore
		}
	}


	/**
	 * Finds outgoing URLs inside a given bit of HTML.
	 *
	 * @param  string $html The HTML.
	 * @return array        Array of URLs.
	 */
	private function find_outgoing_links( $html ) {
		$html = mb_convert_encoding( $html, 'HTML-ENTITIES', get_bloginfo( 'charset' ) );

		libxml_use_internal_errors( true );

		$doc = new \DOMDocument();
		$doc->loadHTML( $html );

		$xpath = new \DOMXPath( $doc );
		$urls  = array();

		foreach ( $xpath->query( '//a/@href' ) as $result ) {
			$urls[] = $result->value;
		}

		return $urls;
	}

	/**
	 * Finds a Webmention enpoint for the given URL.
	 *
	 * @link https://github.com/pfefferle/wordpress-webmention/blob/master/includes/functions.php#L174
	 *
	 * @param  string $url URL to ping.
	 * @return string|null Endpoint URL, or nothing on failure.
	 */
	private function webmention_discover_endpoint( $url ) {
		$parsed_url = wp_parse_url( $url );

		if ( ! isset( $parsed_url['host'] ) ) {
			// Not a URL. This should never happen.
			return;
		}

		$args = array(
			'timeout'             => 15,
			'limit_response_size' => 1048576,
			'redirection'         => 20,
		);

		$response = wp_safe_remote_head(
			esc_url_raw( $url ),
			$args
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		// Check link header.
		$links = wp_remote_retrieve_header( $response, 'link' );

		if ( ! empty( $links ) ) {
			foreach ( (array) $links as $link ) {
				if ( preg_match( '/<(.[^>]+)>;\s+rel\s?=\s?[\"\']?(http:\/\/)?webmention(\.org)?\/?[\"\']?/i', $link, $result ) ) {
					return \WP_Http::make_absolute_url( $result[1], $url );
				}
			}
		}

		if ( preg_match( '#(image|audio|video|model)/#is', wp_remote_retrieve_header( $response, 'content-type' ) ) ) {
			// Not an (X)HTML, SGML, or XML document. No use going further.
			return;
		}

		// Now do a GET since we're going to look in the HTML headers (and we're
		// sure its not a binary file).
		$response = wp_safe_remote_get(
			esc_url_raw( $url ),
			$args
		);

		if ( is_wp_error( $response ) ) {
			return;
		}

		$contents = wp_remote_retrieve_body( $response );
		$contents = mb_convert_encoding( $contents, 'HTML-ENTITIES', mb_detect_encoding( $contents ) );

		libxml_use_internal_errors( true );

		$doc = new \DOMDocument();
		$doc->loadHTML( $contents );

		$xpath = new \DOMXPath( $doc );

		foreach ( $xpath->query( '(//link|//a)[contains(concat(" ", @rel, " "), " webmention ") or contains(@rel, "webmention.org")]/@href' ) as $result ) {
			return \WP_Http::make_absolute_url( $result->value, $url );
		}
	}

	/**
	 * Updates comment (meta)data using microformats.
	 *
	 * @param array  $commentdata Comment (meta)data.
	 * @param string $html        HTML of the webmention source.
	 * @param string $source      Webmention source URL.
	 * @param string $target      Webmention target URL.
	 *
	 * @since 0.4
	 */
	private function parse_microformats( &$commentdata, $html, $source, $target ) {
		// Parse source HTML.
		$mf = \Mf2\parse( $html, esc_url_raw( $source ) );

		if ( empty( $mf['items'][0]['type'][0] ) ) {
			// Nothing to see here.
			return;
		}

		if ( 'h-entry' === $mf['items'][0]['type'][0] ) {
			// Topmost item is an h-entry. Let's try to parse it.
			$this->parse_hentry( $commentdata, $mf['items'][0], $source, $target );
			return;
		} elseif ( 'h-feed' === $mf['items'][0]['type'][0] ) {
			// Topmost item is an h-feed.
			if ( empty( $mf['items'][0]['children'] ) || ! is_array( $mf['items'][0]['children'] ) ) {
				// Nothing to see here.
				return;
			}

			// Loop through its children.
			foreach ( $mf['items'][0]['children'] as $child ) {
				if ( empty( $child['type'][0] ) ) {
					continue;
				}

				if ( $this->parse_hentry( $commentdata, $child, $source, $target ) ) {
					// Found a valid h-entry; stop here.
					return;
				}
			}
		}
	}

	/**
	 * Updates comment (meta)data using h-entry properties.
	 *
	 * @param array  $commentdata Comment (meta)data.
	 * @param array  $hentry      Array describing an h-entry.
	 * @param string $source      Source URL.
	 * @param string $target      Target URL.
	 *
	 * @return bool True on success, false on failure.
	 *
	 * @since 0.4
	 */
	private function parse_hentry( &$commentdata, $hentry, $source, $target ) {
		// Update author name.
		if ( ! empty( $hentry['properties']['author'][0]['properties']['name'][0] ) ) {
			$commentdata['comment_author'] = $hentry['properties']['author'][0]['properties']['name'][0];
		}

		// Update author URL.
		if ( ! empty( $hentry['properties']['author'][0]['properties']['url'][0] ) ) {
			$commentdata['comment_author_url'] = $hentry['properties']['author'][0]['properties']['url'][0];
		}

		// Update comment datetime.
		if ( ! empty( $hentry['properties']['published'][0] ) ) {
			$host = wp_parse_url( $source, PHP_URL_HOST );

			if ( false !== stripos( $host, 'brid-gy.appspot.com' ) ) {
				// Bridgy, we know, uses GMT.
				$commentdata['comment_date']     = get_date_from_gmt( date( 'Y-m-d H:i:s', strtotime( $hentry['properties']['published'][0] ) ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
				$commentdata['comment_date_gmt'] = date( 'Y-m-d H:i:s', strtotime( $hentry['properties']['published'][0] ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			} else {
				// Most WordPress sites do not.
				$commentdata['comment_date']     = date( 'Y-m-d H:i:s', strtotime( $hentry['properties']['published'][0] ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
				$commentdata['comment_date_gmt'] = get_gmt_from_date( date( 'Y-m-d H:i:s', strtotime( $hentry['properties']['published'][0] ) ) ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
			}
		}

		// Update source URL.
		if ( ! empty( $hentry['properties']['url'][0] ) ) {
			$commentdata['comment_meta']['webmention_source'] = esc_url_raw( $hentry['properties']['url'][0] );
		}

		$hentry_kind = '';

		if ( ! empty( $hentry['properties']['content'][0]['html'] ) && false !== stripos( $hentry['properties']['content'][0]['html'], $target ) ) {
			// h-entry at least mentions target.
			$hentry_kind = 'mention';
		}

		if ( ! empty( $hentry['properties']['in-reply-to'] ) && is_array( $hentry['properties']['in-reply-to'] ) && in_array( $target, $hentry['properties']['in-reply-to'], true ) ) {
			// h-entry is in reply to target.
			$hentry_kind = 'reply';
		}

		if ( ! empty( $hentry['properties']['repost-of'] ) && is_array( $hentry['properties']['repost-of'] ) && in_array( $target, $hentry['properties']['repost-of'], true ) ) {
			// h-entry is a repost of target.
			$hentry_kind = 'repost';
		}

		if ( ! empty( $hentry['properties']['bookmark-of'] ) && is_array( $hentry['properties']['bookmark-of'] ) && in_array( $target, $hentry['properties']['bookmark-of'], true ) ) {
			// h-entry is a bookmark of target.
			$hentry_kind = 'bookmark';
		}

		if ( ! empty( $hentry['properties']['like-of'] ) && is_array( $hentry['properties']['like-of'] ) && in_array( $target, $hentry['properties']['like-of'], true ) ) {
			// h-entry is a like/favorite of target.
			$hentry_kind = 'like';
		}

		// Update h-entry kind.
		if ( ! empty( $hentry_kind ) ) {
			$commentdata['comment_meta']['webmention_kind'] = $hentry_kind;
		}

		// Update comment content.
		$comment_content = $commentdata['comment_content'];

		switch ( $hentry_kind ) {
			case 'bookmark':
				$comment_content = __( '&hellip; bookmarked this!', 'webmention-comments' );
				break;

			case 'like':
				$comment_content = __( '&hellip; liked this!', 'webmention-comments' );
				break;

			case 'repost':
				$comment_content = __( '&hellip; reposted this!', 'webmention-comments' );
				break;

			case 'mention':
			case 'reply':
			default:
				if ( ! empty( $hentry['properties']['content'][0]['value'] ) && mb_strlen( $hentry['properties']['content'][0]['value'], 'UTF-8' ) <= 500 &&
					! empty( $hentry['properties']['content'][0]['html'] ) ) {
					// If the mention is short enough, simply show it in its entirety.
					$comment_content = wp_strip_all_tags( $hentry['properties']['content'][0]['html'] );
				} else {
					// Fetch the bit of text surrounding the link to our page.
					$context = $this->fetch_context( $hentry['properties']['content'][0]['html'], $target );

					if ( '' !== $context ) {
						$comment_content = $context;
					} elseif ( ! empty( $hentry['properties']['content'][0]['html'] ) ) {
						// Simply show an excerpt of the webmention source.
						$comment_content = wp_trim_words(
							wp_strip_all_tags( $hentry['properties']['content'][0]['html'] ),
							25,
							' &hellip;'
						);
					}
				}
		}

		$commentdata['comment_content'] = apply_filters(
			'webmention_comments_comment',
			$comment_content,
			$hentry,
			$source,
			$target
		);

		// Well, we've replaced whatever comment data we could find.
		return true;
	}

	/**
	 * Returns the text surrounding a (back)link. Very heavily inspired by
	 * WordPress core.
	 *
	 * @link https://github.com/WordPress/WordPress/blob/1dcf3eef7a191bd0a6cd21d4382b8b5c5a25c886/wp-includes/class-wp-xmlrpc-server.php#L6929
	 *
	 * @param string $html   The remote page's source.
	 * @param string $target The target URL.
	 *
	 * @return string The excerpt, or an empty string if the target isn't found.
	 *
	 * @since 0.8
	 */
	private function fetch_context( $html, $target ) {
		// Work around bug in `strip_tags()`.
		$html = str_replace( '<!DOC', '<DOC', $html );
		$html = preg_replace( '/[\r\n\t ]+/', ' ', $html );
		$html = preg_replace( '/<\/*(h1|h2|h3|h4|h5|h6|p|th|td|li|dt|dd|pre|caption|input|textarea|button|body)[^>]*>/', "\n\n", $html );

		// Remove all script and style tags, including their content.
		$html = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $html );
		// Just keep the tag we need.
		$html = strip_tags( $html, '<a>' );

		$p = explode( "\n\n", $html );

		$preg_target = preg_quote( $target, '|' );

		foreach ( $p as $para ) {
			if ( strpos( $para, $target ) !== false ) {
				preg_match( '|<a[^>]+?' . $preg_target . '[^>]*>([^>]+?)</a>|', $para, $context );

				if ( empty( $context ) ) {
					// The URL isn't in a link context; keep looking.
					continue;
				}

				// We're going to use this fake tag to mark the context in a
				// bit. The marker is needed in case the link text appears more
				// than once in the paragraph.
				$excerpt = preg_replace( '|\</?wpcontext\>|', '', $para );

				// Prevent really long link text.
				if ( strlen( $context[1] ) > 100 ) {
					$context[1] = substr( $context[1], 0, 100 ) . '&#8230;';
				}

				$marker      = '<wpcontext>' . $context[1] . '</wpcontext>';  // Set up our marker.
				$excerpt     = str_replace( $context[0], $marker, $excerpt ); // Swap out the link for our marker.
				$excerpt     = strip_tags( $excerpt, '<wpcontext>' );         // Strip all tags but our context marker.
				$excerpt     = trim( $excerpt );
				$preg_marker = preg_quote( $marker, '|' );
				$excerpt     = preg_replace( "|.*?\s(.{0,200}$preg_marker.{0,200})\s.*|s", '$1', $excerpt );
				$excerpt     = strip_tags( $excerpt );                        // phpcs:ignore

				break;
			}
		}

		if ( empty( $context ) ) {
			// Link to target not found.
			return '';
		}

		return '[&#8230;] ' . esc_html( $excerpt ) . ' [&#8230;]';
	}

	/**
	 * Prints the webmention endpoint.
	 */
	public function webmention_link() {
		echo '<link rel="webmention" href="' . esc_url( get_rest_url( null, '/webmention-comments/v1/create' ) ) . '" />' . PHP_EOL;
	}

	/**
	 * Sets WordPress up for use with this plugin.
	 */
	public function activate() {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'webmention_comments';
		$charset_collate = $wpdb->get_charset_collate();

		// Create database table.
		$sql = "CREATE TABLE $table_name (
			id mediumint(9) UNSIGNED NOT NULL AUTO_INCREMENT,
			source varchar(191) DEFAULT '' NOT NULL,
			post_id bigint(20) UNSIGNED DEFAULT 0 NOT NULL,
			ip varchar(100) DEFAULT '' NOT NULL,
			status varchar(20) DEFAULT '' NOT NULL,
			created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY (id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $sql );

		// Set up cron event for Webmention processing.
		if ( false === wp_next_scheduled( 'process_webmentions' ) ) {
			wp_schedule_event( time(), 'hourly', 'process_webmentions' );
		}

		// Store current database version.
		add_option( 'webmention_comments_db_version', $this->db_version );
	}

	/**
	 * Cleans up after deactivation.
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( 'process_webmentions' );
	}

	/**
	 * Cleans up for real during uninstall.
	 */
	public static function uninstall() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'webmention_comments';
		$wpdb->query( "DROP TABLE IF EXISTS $table_name" ); // phpcs:ignore

		delete_option( 'webmention_comments_db_version' );
	}

	/**
	 * Enables i18n of this plugin.
	 *
	 * @since 0.4
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'webmention-comments', false, basename( dirname( __FILE__ ) ) . '/languages' );
	}
}
