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
		parent::setUp();

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
				'references' => [
					[
						'type'          => 'post',
						'subtype'       => 'attachment',
						'args'          => compact( 'url' ),
					],
				],
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
		$url   = 'https://wpthemetestdata.files.wordpress.com/2008/06/canola2.jpg';
		$title = 'Alt text should inherit description';

		$request = new \WP_REST_Request( 'POST', '/sst/v1/post' );
		$request->add_header( 'content-type', 'application/json' );
		$params = $this->set_post_data(
			[
				'references' => [
					[
						'type'          => 'post',
						'subtype'       => 'attachment',
						'sst_source_id' => $url,
						'args'          => compact( 'url', 'title' ),
					],
				],
			]
		);
		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );

		$this->check_create_post_response( $response, $params );

		$data = $response->get_data();
		$this->assertFalse( empty( $data['posts'][1]['post_id'] ) );
		$this->assertSame(
			$title,
			get_post_meta( $data['posts'][1]['post_id'], '_wp_attachment_image_alt', true )
		);
	}

	public function test_attachment_alt_text_explicit() {
		wp_set_current_user( self::$admin_id );
		$url      = 'https://wpthemetestdata.files.wordpress.com/2008/06/canola2.jpg';
		$title    = 'Image description';
		$alt_text = 'Alt text';

		$request = new \WP_REST_Request( 'POST', '/sst/v1/post' );
		$request->add_header( 'content-type', 'application/json' );
		$params = $this->set_post_data(
			[
				'references' => [
					[
						'type'          => 'post',
						'subtype'       => 'attachment',
						'sst_source_id' => $url,
						'args'          => [
							'url'   => $url,
							'title' => $title,
							'meta'  => [
								'_wp_attachment_image_alt' => $alt_text,
							],
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

	/**
	 * Invalid reference cases to test reference validation.
	 */
	public function invalid_reference_cases() {
		return [
			[ // set 0.
				[
					'type'          => 'post',
					'subtype'       => 'invalid post type',
					'sst_source_id' => 'abc-123',
				],
			],
			[ // set 1.
				[
					'type'          => 'term',
					'subtype'       => 'invalid taxonomy',
					'sst_source_id' => 'abc-123',
				],
			],
			[ // set 2.
				[
					'type'          => 'invalid type',
					'subtype'       => 'pointless',
					'sst_source_id' => 'abc-123',
				],
			],
			[ // set 3.
				[
					'type'    => 'post',
					'subtype' => 'post',
				],
			],
			[ // set 4.
				[
					'type'          => 'post',
					'subtype'       => 'attachment',
					'sst_source_id' => 'abc-123',
				],
			],
			[ // set 5.
				[
					'type'          => 'term',
					'subtype'       => 'post_tag',
				],
			],
			[ // set 6.
				[
					'type'          => 'term',
					'subtype'       => 'post_tag',
					'sst_source_id' => 'abc-123',
				],
			],
		];
	}

	/**
	 * @dataProvider invalid_reference_cases
	 *
	 * @param array $reference (Invalid) reference to set in request.
	 */
	public function test_invalid_references( $reference ) {
		wp_set_current_user( self::$admin_id );

		$request = new \WP_REST_Request( 'POST', '/sst/v1/post' );
		$request->add_header( 'content-type', 'application/json' );
		$params = $this->set_post_data(
			[
				'references' => [ $reference ],
			]
		);
		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'rest_invalid_param', $data['code'] );
	}

	/**
	 * Invalid meta cases to test meta validation.
	 */
	public function invalid_meta_cases() {
		return [
			[ // set 0.
				[
					'invalid array'  => [
						'assoc' => 'assoc array is not valid here',
					],
				],
			],
			[ // set 1.
				[
					'invalid value' => (object) [
						'key' => 'object values are not supported',
					],
				],
			],
			[ // set 2.
				[
					456  => 'invalid key',
				],
			],
			[ // set 3.
				[
					'invalid array'  => [
						[
							'array is not valid here',
						],
					],
				],
			],
		];
	}

	/**
	 * @dataProvider invalid_meta_cases
	 *
	 * @param array $meta (Invalid) meta to set in request.
	 */
	public function test_invalid_meta( $meta ) {
		wp_set_current_user( self::$admin_id );

		$request = new \WP_REST_Request( 'POST', '/sst/v1/post' );
		$request->add_header( 'content-type', 'application/json' );

		$params = [
			'title' => 'Simple Post',
			'type'  => 'sst-promise',
			'meta'  => array_merge(
				[
					'sst_source_id' => 'basic-123',
				],
				$meta
			),
		];

		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertEquals( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'rest_invalid_param', $data['code'] );
	}

	public function test_post_with_ref_posts() {
		wp_set_current_user( self::$admin_id );
		$source_id = 'page-123';

		$request = new \WP_REST_Request( 'POST', '/sst/v1/post' );
		$request->add_header( 'content-type', 'application/json' );
		$params = $this->set_post_data(
			[
				'references' => [
					[
						'type'          => 'post',
						'subtype'       => 'page',
						'sst_source_id' => $source_id,
					],
				],
			]
		);
		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );

		$this->check_create_post_response( $response, $params );

		$data = $response->get_data();
		$this->assertFalse( empty( $data['posts'][1]['sst_source_id'] ) );
		$this->assertFalse( empty( $data['posts'][1]['post_id'] ) );
		$this->assertSame( $source_id, $data['posts'][1]['sst_source_id'] );
		$this->assertSame( $source_id, get_the_title( $data['posts'][1]['post_id'] ) );
		$this->assertSame( 'page', get_post_type( $data['posts'][1]['post_id'] ) );
	}

	public function test_post_with_ref_terms() {
		wp_set_current_user( self::$admin_id );
		$title = 'term-123';

		$request = new \WP_REST_Request( 'POST', '/sst/v1/post' );
		$request->add_header( 'content-type', 'application/json' );
		$params = $this->set_post_data(
			[
				'references' => [
					[
						'type'    => 'term',
						'subtype' => 'post_tag',
						'args'    => [
							'title' => $title,
						],
					],
				],
			]
		);
		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );

		$this->check_create_post_response( $response, $params );

		$data = $response->get_data();
		$this->assertFalse( empty( $data['terms'][0]['term_id'] ) );

		// Assert that the term was created.
		$term = get_term( $data['terms'][0]['term_id'] );
		$this->assertInstanceOf( '\WP_Term', $term );
		$this->assertSame( $title, $term->name );
		$this->assertSame( $title, $term->slug );
		$this->assertSame( 'post_tag', $term->taxonomy );

		// Assert that the term is attached to the post.
		$this->assertFalse( empty( $data['posts'][0]['post_id'] ) );
		$this->assertSame(
			[ $term->term_id ],
			wp_list_pluck(
				get_the_terms( $data['posts'][0]['post_id'], 'post_tag' ),
				'term_id'
			)
		);
	}

	public function test_post_with_ref_terms_with_term_meta() {
		wp_set_current_user( self::$admin_id );

		$request = new \WP_REST_Request( 'POST', '/sst/v1/post' );
		$request->add_header( 'content-type', 'application/json' );
		$params = $this->set_post_data(
			[
				'references' => [
					[
						'type'          => 'term',
						'subtype'       => 'post_tag',
						'sst_source_id' => 'upstream-123',
						'args'          => [
							'title' => 'term-123',
							'meta'  => [
								'test-key' => 'test value',
							],
						],
					],
				],
			]
		);
		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );

		$this->check_create_post_response( $response, $params );

		$data = $response->get_data();
		$this->assertFalse( empty( $data['terms'][0]['term_id'] ) );
		$term_id = $data['terms'][0]['term_id'];

		$this->assertSame(
			'upstream-123',
			get_term_meta( $term_id, 'sst_source_id', true )
		);
		$this->assertSame(
			'test value',
			get_term_meta( $term_id, 'test-key', true )
		);
	}

	public function test_post_with_featured_image() {
		wp_set_current_user( self::$admin_id );
		$url = 'https://wpthemetestdata.files.wordpress.com/2008/06/canola2.jpg';

		$request = new \WP_REST_Request( 'POST', '/sst/v1/post' );
		$request->add_header( 'content-type', 'application/json' );
		$params = $this->set_post_data(
			[
				'references' => [
					[
						'type'         => 'post',
						'subtype'      => 'attachment',
						'args'         => compact( 'url' ),
						'save_to_meta' => '_thumbnail_id',
					],
				],
			]
		);
		$request->set_body( wp_json_encode( $params ) );
		$response = rest_get_server()->dispatch( $request );

		$this->check_create_post_response( $response, $params );

		$data = $response->get_data();
		$this->assertFalse( empty( $data['posts'][0]['post_id'] ) );
		$this->assertFalse( empty( $data['posts'][1]['post_id'] ) );
		$post_id  = $data['posts'][0]['post_id'];
		$image_id = $data['posts'][1]['post_id'];

		// Confirm that the image was stored as the post thumbnail.
		$this->assertSame(
			$image_id,
			(int) get_post_thumbnail_id( $post_id )
		);
	}
}
