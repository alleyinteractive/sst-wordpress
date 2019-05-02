<?php
/**
 * Class SampleTest
 *
 * @package SST
 */

namespace SST\Tests;

/**
 * Sample test case.
 */
class Test_REST_API extends \WP_UnitTestCase {
	protected static $admin_id;
	protected static $editor_id;

	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id  = $factory->user->create(
			[
				'role' => 'administrator',
			]
		);
		self::$editor_id = $factory->user->create(
			[
				'role' => 'editor',
			]
		);
	}

	public static function wpTearDownAfterClass() {
		self::delete_user( self::$admin_id );
		self::delete_user( self::$editor_id );
	}

	public function setUp() {
		global $wp_rest_server;
		$wp_rest_server = new \Spy_REST_Server();
		do_action( 'rest_api_init' );
	}

	protected function set_post_data( $args = [] ) {
		$defaults = [
			'title'   => 'Post Title',
			'content' => 'Post content',
			'excerpt' => 'Post excerpt',
			'name'    => 'test',
			'status'  => 'publish',
			'author'  => get_current_user_id(),
			'type'    => 'post',
			'meta'    => [
				'sst_source_id' => 'abc123',
			],
		];

		return wp_parse_args( $args, $defaults );
	}

	protected function check_create_post_response( $response, $params ) {
		$this->assertNotWPError( $response );
		$response = rest_ensure_response( $response );
		$this->assertEquals( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertFalse( empty( $data['posts'][0]['post_id'] ) );

		$post = get_post( $data['posts'][0]['post_id'] );
		$this->assertSame( $post->ID, $data['posts'][0]['post_id'] );
		$this->assertSame( $post->post_type, $data['posts'][0]['post_type'] );
		$this->assertSame( $post->post_type, $params['type'] );
		$this->assertSame( $post->post_title, $params['title'] );

		$source_id = get_post_meta( $post->ID, 'sst_source_id', true );
		$this->assertSame( $source_id, $data['posts'][0]['sst_source_id'] );
		$this->assertSame( $source_id, $params['meta']['sst_source_id'] );
	}

	/**
	 * A single example test.
	 */
	public function test_create_post() {
		wp_set_current_user( self::$admin_id );

		$request = new \WP_REST_Request( 'POST', '/sst/v1/post' );
		$request->add_header( 'content-type', 'application/json' );
		$params = $this->set_post_data();
		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );

		$this->check_create_post_response( $response, $params );
	}
}
