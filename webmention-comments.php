<?php
/**
 * Plugin Name: Webmention Comments
 * Description: Turn incoming Webmentions, from other blogs or services like Bridgy, into WordPress comments.
 * Author: Jan Boddez
 * Author URI: https://janboddez.tech/
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * GitHub Plugin URI: https://github.com/janboddez/webmention-comments
 * Version: 0.3
 */

// Prevent this script from being loaded directly.
defined( 'ABSPATH' ) or exit;

/**
 * Main plugin class.
 */
class Webmention_Comments {
	private $db_version = '1.0';

	/**
	 * Class constructor.
	 */
	public function __construct() {
		/**
		 * Registers a new REST API endpoint.
		 *
		 * @since 0.2
		 */
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
		add_action( 'rest_api_init', function () {
			register_rest_route( 'webmention-comments/v1', '/create', array(
				'methods' => 'POST',
				'callback' => array( $this, 'store_webmention' ),
			) );
		} );
		add_action( 'process_webmentions', array( $this, 'process_webmentions' ) );
		add_action( 'wp_head', array( $this, 'webmention_link' ) );
	}

	/**
	 * Parses incoming webmentions.
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
			return new WP_Error( 'not_found', 'Not found', array( 'status' => 404 ) );
		}

		// Get IP address.
		if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			// This server seems to be behind Cloudflare.
			$_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
		}

		// Set IP address.
		$ip = preg_replace( '/[^0-9a-fA-F:., ]/', '', $_SERVER['REMOTE_ADDR'] );

		global $wpdb;

		$num_rows = $wpdb->insert(
			$wpdb->prefix . 'webmention_comments',
			array( 
				'source'     => esc_url( $request['source'] ),
				'post_id'    => $post->ID,
				'ip'         => $ip,
				'status'     => 'draft',
				'created_at' => current_time( 'mysql' ),
			)
		);

		if ( false !== $num_rows ) {
			// Create an empty REST response and add an 'Accepted' status code.
			$response = new WP_REST_Response( array() );
			$response->set_status( 202 );
		}

		return new WP_Error( 'invalid_request', 'Invalid source or target', array( 'status' => 400 ) );
	}

	public function process_webmentions() {
		global $wpdb;

		$table_name  = $wpdb->prefix . 'webmention_comments';
		$webmentions = $wpdb->get_results( "SELECT id, source, post_id, ip, created_at FROM $table_name WHERE status = 'draft' LIMIT 10" );

		if ( empty( $webmentions ) || ! is_array( $webmentions ) ) {
			return;
		}

		foreach ( $webmentions as $webmention ) {
			$host = parse_url( $webmention->source, PHP_URL_HOST );

			// Some defaults.
			$comment_data = array(
				'comment_post_ID'      => $webmention->post_id,
				'comment_author'       => $host,
				'comment_author_email' => 'someone@example.com',
				'comment_author_url'   => esc_url( parse_url( $webmention->source, PHP_URL_SCHEME ) . '://' . $host ),
				'comment_author_IP'    => $webmention->ip,
				'comment_content'      => sprintf( __( '&hellip; commented on this. <small>Via <a href="%1$s">%2$s</a>.</small>.', 'webmention-comments' ), esc_url( $webmention->source ), $host ),
				'comment_type'         => '',
				'comment_parent'       => 0,
				'user_id'              => 0,
			);

			// Load microformats2 parser.
			require_once dirname( __FILE__ ) . '/vendor/php-mf2/Mf2/Parser.php';
			$mf = Mf2\fetch( $webmention->source );

			if ( is_array( $mf ) && ! empty( $mf['items'] ) ) {
				// Get whatever first microformat encountered.
				$microformat = $mf['items'][0];

				// Start filling the comment data array.
				if ( ! empty( $microformat['type'][0] ) ) {
					switch( $microformat['type'][0] ) {
						case 'h-feed':
							if ( empty( $microformat['children'][0]['type'][0] ) || 'h-entry' !== $microformat['children'][0]['type'][0] ) {
								// Break out of the switch.
								break;
							} else {
								// Continue processing this h-entry.
								$microformat = $microformat['children'][0];
							}

						case 'h-entry':
						default:
							// Set author name.
							if ( ! empty( $microformat['properties']['author'][0]['properties']['name'][0] ) ) {
								$comment_data['comment_author'] = $microformat['properties']['author'][0]['properties']['name'][0];
							}

							// Set author URL.
							if ( ! empty( $microformat['properties']['author'][0]['properties']['url'][0] ) ) {
								$comment_data['comment_author_url'] = $microformat['properties']['author'][0]['properties']['url'][0];
							}

							// Set comment datetime, from GMT.
							if ( ! empty( $microformat['properties']['published'][0] ) ) {
								if ( 0 !== strpos( $host, 'brid-gy.appspot.com' ) ) {
									// Bridgy uses GMT.
									$comment_data['comment_date']     = get_date_from_gmt( date( 'Y-m-d H:i:s', strtotime( $microformat['properties']['published'][0] ) ) );
									$comment_data['comment_date_gmt'] = date( 'Y-m-d H:i:s', strtotime( $microformat['properties']['published'][0] ) );
								} else {
									// My WordPress site plus Webmention plugin does not.
									$comment_data['comment_date']     = date( 'Y-m-d H:i:s', strtotime( $microformat['properties']['published'][0] ) );
									$comment_data['comment_date_gmt'] = get_gmt_from_date( date( 'Y-m-d H:i:s', strtotime( $microformat['properties']['published'][0] ) ) );
								}
							}

							// Set comment content.
							if ( ! empty( $microformat['properties']['content'][0]['html'] ) ) {
								$comment_data['comment_content'] = wp_trim_words( trim( strip_tags( $microformat['properties']['content'][0]['html'] ) ), 10, ' &hellip;' );

								// Append URL of actual comment source (like Twitter).
								if ( ! empty( $microformat['properties']['url'][0] ) ) {
									$comment_data['comment_content'] .= '' . sprintf( __( '<small>Via <a href="%1$s">%2$s</a>.</small>', 'webmention-comments' ), esc_url( $microformat['properties']['url'][0] ), parse_url( $microformat['properties']['url'][0], PHP_URL_HOST ) );
								}
							}

							break;
					}
				}

				// Disable comment flooding check.
				remove_action( 'check_comment_flood', 'check_comment_flood_db' );

				// Insert new comment.
				$comment_id = wp_new_comment( $comment_data, true );
				$wpdb->update(
					$table_name,
					array( 'status' => 'processed' ),
					array( 'id' => $webmention->id ),
					array( '%s' ),
					array( '%d' )
				);

				if ( is_wp_error( $comment_id ) ) {
					error_log( print_r( $comment_id, true ) );
				}
			}
		}
	}

	public function webmention_link() {
		echo '<link rel="webmention" href="'. esc_url( get_rest_url( '/webmention-comments/v1/create') ) . '" />' . PHP_EOL;
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

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );

		// Store current database version.
		add_option( 'webmention_comments_db_version', $this->db_version );

		// Set up cron event for Webmention processing.
		if ( false === wp_next_scheduled( 'process_webmentions' ) ) {
			wp_schedule_event( time(), 'hourly', 'process_webmentions' );
		}
	}

	/**
	 * Cleans up after deactivation.
	 */
	public function deactivate() {
		// Unset cron event for Webmention processing.
		wp_clear_scheduled_hook( 'process_webmentions' );
	}
}

new Webmention_Comments();
