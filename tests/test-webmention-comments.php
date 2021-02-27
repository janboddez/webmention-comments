<?php

class Test_Webmention_Comments extends \WP_Mock\Tools\TestCase {
	public function setUp() : void {
		\WP_Mock::setUp();
	}

	public function tearDown() : void {
		\WP_Mock::tearDown();
	}

	public function test_webmention_comments_register() {
		$webmention_comments = \Webmention_Comments\Webmention_Comments::get_instance();

		\WP_Mock::userFunction( 'register_deactivation_hook', array(
			'times' => 1,
			'args'  => array(
				dirname( dirname( __FILE__ ) ) . '/webmention-comments.php',
				array( $webmention_comments, 'deactivate' ),
			),
		) );

		\WP_Mock::userFunction( 'register_uninstall_hook', array(
			'times' => 1,
			'args'  => array(
				dirname( dirname( __FILE__ ) ) . '/webmention-comments.php',
				array( \Webmention_Comments\Webmention_Comments::class, 'uninstall' ),
			),
		) );

		\WP_Mock::expectActionAdded( 'init', array( $webmention_comments, 'activate' ) );
		\WP_Mock::expectActionAdded( 'plugins_loaded', array( $webmention_comments, 'load_textdomain' ) );
		\WP_Mock::expectActionAdded( 'process_webmentions', array( $webmention_comments, 'process_webmentions' ) );
		\WP_Mock::expectActionAdded( 'transition_post_status', array( $webmention_comments, 'schedule_webmention' ), 10, 3 );
		\WP_Mock::expectActionAdded( 'webmention_comments_send', array( $webmention_comments, 'send_webmention' ) );
		\WP_Mock::expectActionAdded( 'add_meta_boxes', array( $webmention_comments, 'add_meta_box' ) );
		\WP_Mock::expectActionAdded( 'wp_head', array( $webmention_comments, 'webmention_link' ) );

		$webmention_comments->register();

		$this->assertHooksAdded();
	}
}

