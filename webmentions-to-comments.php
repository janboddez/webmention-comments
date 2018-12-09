<?php
/**
 * Plugin Name: Webmentions to Comments
 * Description: Receive webmentions from services like Bridgy.
 * Author: Jan Boddez
 * Author URI: https://janboddez.tech/
 * License: GNU General Public License v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Version: 0.1
 */

// Prevent this script from being loaded directly.
defined( 'ABSPATH' ) or exit;

add_action( 'rest_api_init', function () {
	register_rest_route( 'jb-webmentions/v1', '/create', array(
		'methods' => 'POST',
		'callback' => 'jb_receive_webmention',
	) );
} );

function jb_receive_webmention( $request ) {
	// Verify source nor target are invalid URLs.
	if ( empty( $request['source'] ) || empty( $request['target'] ) || ! filter_var( $request['source'], FILTER_VALIDATE_URL ) || ! filter_var( $request['target'], FILTER_VALIDATE_URL ) ) {
		return new WP_Error( 'invalid_request', 'Invalid source or target', array( 'status' => 400 ) );
	}

	$host = parse_url( $request['source'], PHP_URL_HOST );

	// We currently support only Bridgy. This may change in the future.
	if ( 0 !== strpos( $host, 'brid-gy.appspot.com' ) ) {
		return new WP_Error( 'invalid_request', 'Invalid source or target', array( 'status' => 400 ) );
	}

	// Get the target post's slug, sans permalink front.
	global $wp_rewrite;
	$slug = str_replace(
		$wp_rewrite->front,
		'',
		parse_url( $request['target'], PHP_URL_PATH )
	);
	$slug = trim( $slug, '/' );

	// Fetch the post.
	$post = get_page_by_path( $slug, OBJECT, 'post' );

	if ( empty( $post ) ) {
		return new WP_Error( 'not_found', 'Not found', array( 'status' => 404 ) );
	}

	// Some defaults.
	$comment_data = array(
		'comment_post_ID' => $post->ID,
		'comment_author' => 'A Certain Someone',
		'comment_author_email' => 'someone@example.com',
		'comment_author_url' => 'http://example.com',
		'comment_content' => 'Comment messsage ...',
		'comment_type' => '',
		'comment_parent' => 0,
		'user_id' => 0,
	);

	// Load microformats2 parser.
	require_once dirname( __FILE__ ) . '/vendor/php-mf2/Mf2/Parser.php';
	$mf = Mf2\fetch( $request['source'] );

	if ( ! is_array( $mf ) || empty( $mf['items'] ) ) {
		return new WP_Error( 'invalid_request', 'Invalid source or target', array( 'status' => 400 ) );
	}

	// Get the h-entry.
	$microformat = $mf['items'][0];

	// Start filling the comment data array.
	if ( ! empty( $microformat['type'][0] ) && $microformat['type'][0] === 'h-entry' ) {
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
			$comment_data['comment_date'] = get_date_from_gmt( date( 'Y-m-d H:i:s', strtotime( $microformat['properties']['published'][0] ) ) );
			$comment_data['comment_date_gmt'] = date( 'Y-m-d H:i:s', strtotime( $microformat['properties']['published'][0] ) );
		}

		// Set comment content.
		if ( ! empty( $microformat['properties']['content'][0]['html'] ) ) {
			$comment_data['comment_content'] = $microformat['properties']['content'][0]['html'];

			// Append URL of actual comment source (like Twitter).
			if ( ! empty( $microformat['properties']['url'][0] ) ) {
				$comment_data['comment_content'] .= sprintf( ' <small>Via <a href="%1$s">%2$s</a>.</small>', esc_url( $microformat['properties']['url'][0] ), parse_url( $microformat['properties']['url'][0], PHP_URL_HOST ) );
			}
		}

		// Get IP address.
		if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			// This server seems to be behind Cloudflare.
			$_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_CF_CONNECTING_IP'];
		}

		// Set IP address (to, most likely, Bridgy's).
		$comment_data['comment_author_ip'] = preg_replace( '/[^0-9a-fA-F:., ]/', '', $_SERVER['REMOTE_ADDR'] );

		// Disable comment flooding check.
		remove_action( 'check_comment_flood', 'check_comment_flood_db' );

		// Insert new comment.
		$comment_id = wp_new_comment( $comment_data, true );

		if ( ! is_wp_error( $comment_id ) ) {
			// Create an empty rest response and add an 'Accepted' status code.
			$response = new WP_REST_Response( array() );
			$response->set_status( 202 );

			return $response;
		} elseif ( 'comment_duplicate' === $comment_id->get_error_code() ) {
			// Looks like a duplicate comment. Safe to return.
			return $comment_id;
		}
	}

	return new WP_Error( 'invalid_request', 'Invalid source or target', array( 'status' => 400 ) );
}
