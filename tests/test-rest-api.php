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
				'sst_source_id'      => 'abc123',
				'unregistered_meta'  => 'Unregistered Meta',
				'unregistered_array' => [
					'value 1',
					'value 2',
				],
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

		$this->assertSame(
			get_post_meta( $post->ID, 'unregistered_meta', true ),
			$params['meta']['unregistered_meta']
		);
		$this->assertSame(
			get_post_meta( $post->ID, 'unregistered_array' ),
			$params['meta']['unregistered_array']
		);
	}

	public function test_create_basic_promise_post() {
		wp_set_current_user( self::$admin_id );

		$request = new \WP_REST_Request( 'POST', '/sst/v1/post' );
		$request->add_header( 'content-type', 'application/json' );

		// Set the absolute minimum amount of data required to create a post.
		$params = [
			'title' => 'Simple Post',
			'type'  => 'sst-promise',
			'meta'  => [
				'sst_source_id' => 'basic-123',
			],
		];

		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );

		$this->assertNotWPError( $response );
		$this->assertInstanceOf( '\WP_REST_Response', $response );
		$this->assertEquals( 201, $response->get_status() );

		$data = $response->get_data();
		$this->assertSame( $params['meta']['sst_source_id'], $data['posts'][0]['sst_source_id'] );
		$this->assertSame( $params['type'], $data['posts'][0]['post_type'] );
	}

	public function test_sst_permissions() {
		wp_set_current_user( self::$editor_id );

		$request = new \WP_REST_Request( 'POST', '/sst/v1/post' );
		$request->add_header( 'content-type', 'application/json' );

		$params = [
			'title' => 'Simple Post',
			'type'  => 'sst-promise',
			'meta'  => [
				'sst_source_id' => 'basic-123',
			],
		];

		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 403, $response->get_status() );
	}

	public function test_create_post() {
		wp_set_current_user( self::$admin_id );

		$request = new \WP_REST_Request( 'POST', '/sst/v1/post' );
		$request->add_header( 'content-type', 'application/json' );
		$params = $this->set_post_data();
		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );

		$this->check_create_post_response( $response, $params );
	}

	public function test_post_with_attachments() {
		wp_set_current_user( self::$admin_id );
		$url = 'https://wpthemetestdata.files.wordpress.com/2008/06/canola2.jpg';

		$request = new \WP_REST_Request( 'POST', '/sst/v1/post' );
		$request->add_header( 'content-type', 'application/json' );
		$params = $this->set_post_data(
			[
				'attachments' => [ compact( 'url' ) ],
			]
		);
		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );

		$this->check_create_post_response( $response, $params );

		$data = $response->get_data();
		$this->assertFalse( empty( $data['posts'][1]['sst_source_id'] ) );
		$this->assertSame( $url, $data['posts'][1]['sst_source_id'] );
	}

	public function test_attachment_alt_text_inherted_from_desc() {
		wp_set_current_user( self::$admin_id );
		$url         = 'https://wpthemetestdata.files.wordpress.com/2008/06/canola2.jpg';
		$description = 'Alt text should inherit description';

		$request = new \WP_REST_Request( 'POST', '/sst/v1/post' );
		$request->add_header( 'content-type', 'application/json' );
		$params = $this->set_post_data(
			[
				'attachments' => [ compact( 'url', 'description' ) ],
			]
		);
		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );

		$this->check_create_post_response( $response, $params );

		$data = $response->get_data();
		$this->assertFalse( empty( $data['posts'][1]['post_id'] ) );
		$this->assertSame(
			$description,
			get_post_meta( $data['posts'][1]['post_id'], '_wp_attachment_image_alt', true )
		);
	}

	public function test_attachment_alt_text_explicit() {
		wp_set_current_user( self::$admin_id );
		$url         = 'https://wpthemetestdata.files.wordpress.com/2008/06/canola2.jpg';
		$description = 'Image description';
		$alt_text    = 'Alt text';

		$request = new \WP_REST_Request( 'POST', '/sst/v1/post' );
		$request->add_header( 'content-type', 'application/json' );
		$params = $this->set_post_data(
			[
				'attachments' => [
					[
						'url'         => $url,
						'description' => $description,
						'meta'        => [
							'_wp_attachment_image_alt' => $alt_text,
						],
					],
				],
			]
		);
		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );

		$data = $response->get_data();
		$this->check_create_post_response( $response, $params );

		$this->assertFalse( empty( $data['posts'][1]['post_id'] ) );
		$this->assertSame(
			$alt_text,
			get_post_meta( $data['posts'][1]['post_id'], '_wp_attachment_image_alt', true )
		);
	}
}
