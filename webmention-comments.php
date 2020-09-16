<?php
/**
 * Plugin Name:       Webmention Comments
 * Description:       Turn incoming Webmentions, from other blogs or services like Bridgy, into WordPress comments.
 * GitHub Plugin URI: https://github.com/janboddez/webmention-comments
 * Author:            Jan Boddez
 * Author URI:        https://janboddez.tech/
 * License:           GNU General Public License v3
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.html
 * Textdomain:        webmention-comments
 * Version:           0.9.1
 *
 * @author  Jan Boddez <jan@janboddez.be>
 * @license http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @package Webmention_Comments
 */

namespace Webmention_Comments;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load dependencies.
require_once dirname( __FILE__ ) . '/vendor/autoload.php';
require_once dirname( __FILE__ ) . '/includes/class-webmention-comments.php';

$webmention_comments = Webmention_Comments::get_instance();
$webmention_comments->register();
