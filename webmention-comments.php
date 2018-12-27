<?php
/**
 * Plugin Name: Webmention Comments
 * Description: Turn incoming Webmentions, from other blogs or services like Bridgy, into WordPress comments.
 * GitHub Plugin URI: https://github.com/janboddez/webmention-comments
 * Author: Jan Boddez
 * Author URI: https://janboddez.tech/
 * License: GNU General Public License v3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Textdomain: webmention-comments
 * Version: 0.5
 */

// Prevent this script from being loaded directly.
defined( 'ABSPATH' ) or exit;

// Load microformats2 parser and Webmention Client.
require_once dirname( __FILE__ ) . '/vendor/php-mf2/Mf2/Parser.php';
require_once dirname( __FILE__ ) . '/vendor/mention-client-php/src/IndieWeb/MentionClient.php';

/**
 * Main plugin class.
 *
 * @since 0.2
 */
class Webmention_Comments {
	/**
	 * Database table version, in case we ever want to upgrade its structure.
	 *
	 * @since 0.3
	 */
	private $db_version = '1.0';

	/**
	 * Class constructor.
	 *
	 * @since 0.2
	 */
	public function __construct() {
		// Activation, deactivation and uninstall hooks.
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
		register_uninstall_hook( __FILE__, array( __CLASS__, 'uninstall' ) );

		// Register a new REST API route (it's that easy).
		add_action( 'rest_api_init', function() {
			register_rest_route( 'webmention-comments/v1', '/create', array(
				'methods' => 'POST',
				'callback' => array( $this, 'store_webmention' ),
			) );
		} );

		add_action( 'process_webmentions', array( $this, 'process_webmentions' ) );
		add_action( 'publish_post', array( $this, 'send_webmention' ), 10, 2 );
		add_action( 'wp_head', array( $this, 'webmention_link' ) );
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Stores incoming webmentions and that's about it.
	 *
	 * @param WP_REST_Request $request WP REST API request.
	 *
	 * @since 0.2
	 */
	public function store_webmention( $request ) {
		// Verify source nor target are invalid URLs.
		if ( empty( $request['source'] ) || empty( $request['target'] ) || ! filter_var( $request['source'], FILTER_VALIDATE_URL ) || ! filter_var( $request['target'], FILTER_VALIDATE_URL ) ) {
			return new WP_Error( 'invalid_request', 'Invalid source or target', array( 'status' => 400 ) );
		}

		global $wp_rewrite;

		// Get the target post's slug, sans permalink front.
		$slug = trim( str_replace(
			$wp_rewrite->front,
			'',
			parse_url( $request['target'], PHP_URL_PATH )
		), '/' );

		// Fetch the post.
		$post = get_page_by_path( $slug, OBJECT, 'post' );

		if ( empty( $post ) || 'publish' !== get_post_status( $post->ID ) ) {
			// Not found.
			return new WP_Error( 'not_found', 'Not found', array( 'status' => 404 ) );
		}

		// Set sender's IP address.
		$ip = preg_replace( '/[^0-9a-fA-F:., ]/', '', apply_filters( 'webmention_comments_sender_ip', $_SERVER['REMOTE_ADDR'], $request ) );

		global $wpdb;

		$num_rows = $wpdb->insert(
			$wpdb->prefix . 'webmention_comments',
			array(
				'source' => esc_url( $request['source'] ),
				'post_id' => $post->ID,
				'ip' => $ip,
				'status' => 'draft',
				'created_at' => current_time( 'mysql' ),
			)
		);

		if ( false !== $num_rows ) {
			// Create an empty REST response and add an 'Accepted' status code.
			$response = new WP_REST_Response( array() );
			$response->set_status( 202 );

			return $response;
		}

		return new WP_Error( 'invalid_request', 'Invalid source or target', array( 'status' => 400 ) );
	}

	/**
	 * Processes queued webmentions. Typically triggered by WP Cron.
	 *
	 * @since 0.3
	 */
	public function process_webmentions() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'webmention_comments';
		$webmentions = $wpdb->get_results( "SELECT id, source, post_id, ip, created_at FROM $table_name WHERE status = 'draft' LIMIT 10" );

		if ( empty( $webmentions ) || ! is_array( $webmentions ) ) {
			// Empty queue.
			return;
		}

		foreach ( $webmentions as $webmention ) {
			// Grab source domain.
			$host = parse_url( $webmention->source, PHP_URL_HOST );

			// Some defaults.
			$commentdata = array(
				'comment_post_ID' => $webmention->post_id,
				'comment_author' => $host,
				'comment_author_email' => 'someone@example.com',
				'comment_author_url' => esc_url( parse_url( $webmention->source, PHP_URL_SCHEME ) . '://' . $host ),
				'comment_author_IP' => $webmention->ip,
				// Note: The <small> tag may be stripped out if not added to the allowed tags elsewhere.
				'comment_content' => sprintf( __( '&hellip; commented on this. <small>Via <a href="%1$s">%2$s</a>.</small>', 'webmention-comments' ), esc_url( $webmention->source ), $host ),
				'comment_type' => '',
				'comment_parent' => 0,
				'user_id' => 0,
			);

			// Search source for supported microformats. Returns void.
			$this->parse_microformats( $commentdata, $webmention->source, get_permalink( $webmention->post_id ) );

			// Disable comment flooding check.
			remove_action( 'check_comment_flood', 'check_comment_flood_db' );

			// Insert new comment, mark webmention as processed.
			$comment_id = wp_new_comment( $commentdata, true );

			$status = 'complete';

			if ( is_wp_error( $comment_id ) ) {
				// For troubleshooting.
				error_log( print_r( $comment_id, true ) );
				if ( in_array( 'comment_duplicate', $comment_id->get_error_codes() ) ) {
					$status = 'duplicate';
				}
			}

			$wpdb->update(
				$table_name,
				array( 'status' => $status ),
				array( 'id' => $webmention->id ),
				array( '%s' ),
				array( '%d' )
			);
		}
	}

	/**
	 * Attempts to send webmentions to all URLs mentioned in a post.
	 *
	 * @param int $post_id Unique ID of the WordPress post.
	 * @param WP_Post $post The corresponding WP_Post object.
	 *
	 * @since 0.5
	 */
	public function send_webmention( $post_id, $post ) {
		// Init Webmention Client.
		$client = new IndieWeb\MentionClient();

		// Fetch our post's HTML.
		$html = apply_filters( 'the_content', $post->post_content );

		// Scan it for outgoing links.
		$urls = $client->findOutgoingLinks( $html );

		if ( ! empty( $urls ) && is_array( $urls ) ) {
			// Loop through all of the links.
			foreach ( $urls as $url ) {
				// Try and find a Webmention endpoint.
				$endpoint = $client->discoverWebmentionEndpoint( $url );

				if ( $endpoint ) {
					// Send the webmention.
					$response = wp_safe_remote_post( $endpoint, array(
						'body'=> array(
							'source' => rawurlencode( get_permalink( $post_id ) ),
							'target' => rawurlencode( $url ),
						),
					) );

					if ( is_wp_error( $response ) ) {
						// Something went wrong.
						error_log( print_r( $response->get_error_messages(), true ) );
					}
				}
			}
		}
	}

	/**
	 * Updates comment (meta)data using microformats.
	 *
	 * @param array &$commentdata Comment (meta)data.
	 * @param string $source Webmention source URL.
	 * @param string $target Webmention target URL.
	 *
	 * @since 0.4
	 */
	private function parse_microformats( &$commentdata, $source, $target ) {
		// Parse source URL.
		$mf = Mf2\fetch( $source );

		if ( empty( $mf['items'][0]['type'][0] ) ) {
			// Nothing to see here. Bail.
			return;
		}

		if ( 'h-entry' === $mf['items'][0]['type'][0] ) {
			// Topmost item is an h-entry. Let's try to parse it.
			$this->parse_hentry( $commentdata, $mf['items'][0], $source, $target );
		} elseif ( 'h-feed' === $mf['items'][0]['type'][0] ) {
			// Topmost item is an h-feed.
			if ( empty( $mf['items'][0]['children'] ) || ! is_array( $mf['items'][0]['children'] ) ) {
				return;
			}

			// Loop through its children.
			foreach ( $mf['items'][0]['children'] as $child ) {
				if ( ! empty( $child['type'][0] ) && 'h-entry' === $child['type'][0] && $this->parse_hentry( $commentdata, $child, $source, $target ) ) {
					// Got what we need. Break out of the loop.
					break;
				}
			}
		}
	}

	/**
	 * Updates comment (meta)data using h-entry properties.
	 *
	 * @param array &$commentdata Comment (meta)data.
	 * @param array $hentry Array describing an h-entry.
	 * @param string $source Source URL.
	 * @param string $target Target URL.
	 *
	 * @return bool If the h-entry got parsed.
	 *
	 * @since 0.4
	 */
	private function parse_hentry( &$commentdata, $hentry, $source, $target ) {
		$valid_reply = false;

		if ( ! empty( $hentry['properties']['in-reply-to'] ) && is_array( $hentry['properties']['in-reply-to'] ) && in_array( $target, $hentry['properties']['in-reply-to'] ) ) {
			// h-entry is in reply to target.
			$valid_reply = true;
		}

		if ( ! empty( $hentry['properties']['content'][0]['html'] ) && false !== stripos( $hentry['properties']['content'][0]['html'], $target ) ) {
			// h-entry mentions target.
			$valid_reply = true;
		}

		if ( ! $valid_reply ) {
			// No mention of our target URL. This h-entry may not be one we're after.
			return false;
		}

		// Set author name.
		if ( ! empty( $hentry['properties']['author'][0]['properties']['name'][0] ) ) {
			$commentdata['comment_author'] = $hentry['properties']['author'][0]['properties']['name'][0];
		}

		// Set author URL.
		if ( ! empty( $hentry['properties']['author'][0]['properties']['url'][0] ) ) {
			$commentdata['comment_author_url'] = $hentry['properties']['author'][0]['properties']['url'][0];
		}

		// Set comment datetime.
		if ( ! empty( $hentry['properties']['published'][0] ) ) {
			$host = parse_url( $source, PHP_URL_HOST );

			if ( 0 !== stripos( $host, 'brid-gy.appspot.com' ) ) {
				// Bridgy, we know, uses GMT.
				$commentdata['comment_date'] = get_date_from_gmt( date( 'Y-m-d H:i:s', strtotime( $hentry['properties']['published'][0] ) ) );
				$commentdata['comment_date_gmt'] = date( 'Y-m-d H:i:s', strtotime( $hentry['properties']['published'][0] ) );
			} else {
				// Most WordPress sites do not.
				$commentdata['comment_date'] = date( 'Y-m-d H:i:s', strtotime( $hentry['properties']['published'][0] ) );
				$commentdata['comment_date_gmt'] = get_gmt_from_date( date( 'Y-m-d H:i:s', strtotime( $hentry['properties']['published'][0] ) ) );
			}
		}

		// Set comment content.
		if ( ! empty( $hentry['properties']['content'][0]['html'] ) ) {
			$commentdata['comment_content'] = wp_trim_words( trim( strip_tags( $hentry['properties']['content'][0]['html'] ) ), 25, ' &hellip;' );

			// Append URL of actual comment source (like a tweet or blog post).
			if ( ! empty( $hentry['properties']['url'][0] ) ) {
				$commentdata['comment_content'] .= ' ' . sprintf( __( '<small>Via <a href="%1$s">%2$s</a>.</small>', 'webmention-comments' ), esc_url( $hentry['properties']['url'][0] ), parse_url( $hentry['properties']['url'][0], PHP_URL_HOST ) );
			}
		}

		// Well, we've replaced whatever comment data we could find.
		return true;
	}

	/**
	 * Prints the webmention endpoint.
	 */
	public function webmention_link() {
		echo '<link rel="webmention" href="'. esc_url( get_rest_url( null, '/webmention-comments/v1/create' ) ) . '" />' . PHP_EOL;
	}

	/**
	 * Sets WordPress up for use with this plugin.
	 */
	public function activate() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'webmention_comments';
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

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
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
		$wpdb->query( "DROP TABLE IF EXISTS $table_name" );

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

new Webmention_Comments();
