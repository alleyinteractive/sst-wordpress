<?php
/**
 * REST API Integration
 *
 * @package SST
 */

namespace SST;

/**
 * REST API class for SST.
 */
class REST_API extends \WP_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'sst/v1';
		$this->rest_base = 'post';
	}

	/**
	 * Registers the routes for the objects of the controller.
	 *
	 * @see register_rest_route()
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'create_item_permissions_check' ],
					'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::CREATABLE ),
				],
				'schema' => [ $this, 'get_response_schema' ],
			]
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			[
				'args'   => [
					'id' => [
						'description' => __( 'WordPress Post ID for the object.', 'sst' ),
						'type'        => 'integer',
					],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'update_item_permissions_check' ],
					'args'                => $this->get_endpoint_args_for_item_schema( \WP_REST_Server::EDITABLE ),
				],
				'schema' => [ $this, 'get_response_schema' ],
			]
		);
	}

	/**
	 * Register post meta used by SST.
	 */
	public function register_meta() {
		register_post_meta(
			'',
			'sst_source_id',
			[
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
				'single'            => true,
				'type'              => 'string',
			]
		);
	}

	/**
	 * Get the post, if the ID is valid.
	 *
	 * @since 4.7.2
	 *
	 * @param int $id Supplied ID.
	 * @return WP_Post|WP_Error Post object if ID is valid, WP_Error otherwise.
	 */
	protected function get_post( $id ) {
		$error = new \WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'sst' ), [ 'status' => 404 ] );
		if ( (int) $id <= 0 ) {
			return $error;
		}

		$post = get_post( (int) $id );
		if ( empty( $post ) || empty( $post->ID ) ) {
			return $error;
		}

		return $post;
	}

	/**
	 * Dispatch another REST API request.
	 *
	 * @param string $method Request method.
	 * @param string $route  REST API route.
	 * @param array  $args   {
	 *     Request arguments.
	 *
	 *     @type mixed $body Request body.
	 * }
	 * @return WP_REST_Response REST response.
	 */
	protected function dispatch_request( $method, $route, $args = [] ) {
		$request = new \WP_REST_Request( $method, $route );
		$request->add_header( 'content-type', 'application/json' );

		if ( ! empty( $args['body'] ) ) {
			$request->set_body( wp_json_encode( $args['body'] ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * Add a created object to the response.
	 *
	 * @param array             $response Response array to which to add an
	 *                                    object.
	 * @param \WP_Post|\WP_Term $object   Post or term object.
	 * @return array
	 */
	protected function add_object_to_response( array $response, $object ): array {
		if ( $object instanceof \WP_Post ) {
			$response['posts'][] = [
				'post_id'   => $object->ID,
				'post_type' => $object->post_type,
				'sst_source_id' => get_post_meta( $object->ID, 'sst_source_id', true ),
			];
		} elseif ( $object instanceof \WP_Term ) {
			$response['terms'][] = [
				'term_id'  => $object->term_id,
				'taxonomy' => $object->taxonomy,
				'slug'     => $object->slug,
			];
		}

		return $response;
	}

	/**
	 * Check if the current user is allowed to authenticate SST.
	 */
	protected function authenticate_sst_permissions_check() {
		if ( ! current_user_can( 'authenticate_sst' ) ) {
			return new \WP_Error(
				'rest_cannot_create',
				__( 'Sorry, you are not allowed to authenticate.', 'sst' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Checks if a given request has access to create a post.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to create items, WP_Error object otherwise.
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! empty( $request['id'] ) ) {
			return new \WP_Error(
				'rest_post_exists',
				__( 'Cannot create existing post.', 'sst' ),
				[ 'status' => 400 ]
			);
		}

		return $this->authenticate_sst_permissions_check();
	}

	/**
	 * Creates a single post.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_item( $request ) {
		$created_objects = [];

		// Validate the post to be inserted.
		$post = $this->prepare_item_for_database( $request );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		// Defer to the core REST API endpoint to create the post.
		$post_type_obj = get_post_type_object( $request['type'] );
		$response      = $this->dispatch_request(
			'POST',
			'/wp/v2/' . ( $post_type_obj->rest_base ?? $post_type_obj->name ),
			[ 'body' => $post ]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( $response->get_status() >= 400 ) {
			return $response;
		} elseif ( 201 !== $response->get_status() ) {
			return new \WP_Error(
				'unexpected-response',
				sprintf(
					/* translators: %d: response code from creating post */
					__( 'Unexpected response creating post: %d.', 'sst' ),
					$response->get_status()
				),
				[ 'status' => 400 ]
			);
		}

		$data = $response->get_data();
		if ( ! empty( $data['id'] ) ) {
			$created_objects = $this->add_object_to_response(
				$created_objects,
				get_post( $data['id'] )
			);
		}

		$api_response = rest_ensure_response( $created_objects );
		$api_response->set_status( 201 );
		return $api_response;
	}

	/**
	 * Checks if a given request has access to update a post.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to update the item, WP_Error object otherwise.
	 */
	public function update_item_permissions_check( $request ) {
		$post = $this->get_post( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		return $this->authenticate_sst_permissions_check();
	}

	/**
	 * Updates a single post.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function update_item( $request ) {
		$data = [];

		$response = rest_ensure_response( $data );

		return $response;
	}

	/**
	 * Prepares a single post for create or update.
	 *
	 * @since 4.7.0
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return stdClass|WP_Error Post object or WP_Error.
	 */
	protected function prepare_item_for_database( $request ) {
		$post_data = (array) $request->get_json_params();

		// First, check required fields.
		if ( empty( $request['meta']['sst_source_id'] ) ) {
			return new \WP_Error(
				'empty-source_id',
				__( 'Post is missing source ID (`meta.source_id`)', 'sst' ),
				[ 'status' => 400 ]
			);
		}
		if ( empty( $request['title'] ) ) {
			return new \WP_Error(
				'empty-title',
				__( 'Post is missing title (`title`)', 'sst' ),
				[ 'status' => 400 ]
			);
		}
		if ( empty( $request['type'] ) ) {
			return new \WP_Error(
				'empty-type',
				__( 'Post is missing post type (`type`)', 'sst' ),
				[ 'status' => 400 ]
			);
		}

		// Remove fields that shouldn't be passed to the saved post.
		unset( $post_data['attachments'], $post_data['type'] );

		return (object) $post_data;
	}

	/**
	 * Retrieves the POST response schema, conforming to JSON Schema.
	 *
	 * @return array Item schema data.
	 */
	public function get_response_schema() {
		$schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'sst-post',
			'type'       => 'object',
			'properties' => [
				'posts' => [
					'type'        => 'array',
					'description' => __( 'Posts that were created or updated during the request.', 'sst' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'sst_source_id' => [
								'type'        => 'string',
								'description' => __( 'The original source ID.', 'sst' ),
							],
							'post_id'   => [
								'type'        => 'integer',
								'description' => __( 'The WordPress post ID.', 'sst' ),
							],
							'post_type' => [
								'type'        => 'string',
								'description' => __( 'The WordPress post type.', 'sst' ),
							],
						],
					],
				],
				'terms' => [
					'type'        => 'array',
					'description' => __( 'Terms that were created or updated during the request.', 'sst' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'slug'     => [
								'type'        => 'string',
								'description' => __( 'The term slug.', 'sst' ),
							],
							'taxonomy' => [
								'type'        => 'string',
								'description' => __( 'The term taxonomy.', 'sst' ),
							],
							'term_id'  => [
								'type'        => 'integer',
								'description' => __( 'The term ID.', 'sst' ),
							],
						],
					],
				],
			],
		];

		return $schema;
	}

	/**
	 * Retrieves the SST schema, primarily intended to be turned into request
	 * args, conforming to JSON Schema.
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema() {
		$schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'sst-post',
			'type'       => 'object',
			// Base properties for every Post.
			'properties' => [
				'date'           => [
					'description' => __( "The date the object was published, in the site's timezone.", 'sst' ),
					'type'        => 'string',
					'format'      => 'date-time',
				],
				'date_gmt'       => [
					'description' => __( 'The date the object was published, as GMT.', 'sst' ),
					'type'        => 'string',
					'format'      => 'date-time',
				],
				'slug'           => [
					'description' => __( 'An alphanumeric identifier for the object unique to its type.', 'sst' ),
					'type'        => 'string',
					'arg_options' => [
						'sanitize_callback' => [ $this, 'sanitize_slug' ],
					],
				],
				'status'         => [
					'description' => __( 'A named status for the object.', 'sst' ),
					'type'        => 'string',
					'enum'        => array_keys( get_post_stati( [ 'internal' => false ] ) ),
				],
				'type'           => [
					'description' => __( 'Type of Post for the object.', 'sst' ),
					'type'        => 'string',
					'enum'        => array_values( get_post_types() ),
					'required'    => true,
				],
				'parent'         => [
					'description' => __( 'The ID for the parent of the object.', 'sst' ),
					'type'        => 'integer',
				],
				'title'          => [
					'description' => __( 'The title for the object.', 'sst' ),
					'type'        => 'object',
					'arg_options' => [
						'sanitize_callback' => null, // Note: sanitization implemented in WP_REST_Posts_Controller::prepare_item_for_database().
						'validate_callback' => null, // Note: validation implemented in WP_REST_Posts_Controller::prepare_item_for_database().
					],
					'properties'  => [
						'raw' => [
							'description' => __( 'Title for the object, as it exists in the database.', 'sst' ),
							'type'        => 'string',
						],
					],
					'required'    => true,
				],
				'content'        => [
					'description' => __( 'The content for the object.', 'sst' ),
					'type'        => 'object',
					'arg_options' => [
						'sanitize_callback' => null, // Note: sanitization implemented in WP_REST_Posts_Controller::prepare_item_for_database().
						'validate_callback' => null, // Note: validation implemented in WP_REST_Posts_Controller::prepare_item_for_database().
					],
					'properties'  => [
						'raw' => [
							'description' => __( 'Content for the object, as it exists in the database.', 'sst' ),
							'type'        => 'string',
						],
					],
				],
				'author'         => [
					'description' => __( 'The ID for the author of the object.', 'sst' ),
					'type'        => 'integer',
				],
				'excerpt'        => [
					'description' => __( 'The excerpt for the object.', 'sst' ),
					'type'        => 'object',
					'arg_options' => [
						'sanitize_callback' => null, // Note: sanitization implemented in WP_REST_Posts_Controller::prepare_item_for_database().
						'validate_callback' => null, // Note: validation implemented in WP_REST_Posts_Controller::prepare_item_for_database().
					],
					'properties'  => [
						'raw' => [
							'description' => __( 'Excerpt for the object, as it exists in the database.', 'sst' ),
							'type'        => 'string',
						],
					],
				],
				'featured_media' => [
					'description' => __( 'The ID of the featured media for the object.', 'sst' ),
					'type'        => 'integer',
				],
				'comment_status' => [
					'description' => __( 'Whether or not comments are open on the object.', 'sst' ),
					'type'        => 'string',
					'enum'        => [ 'open', 'closed' ],
				],
				'ping_status'    => [
					'description' => __( 'Whether or not the object can be pinged.', 'sst' ),
					'type'        => 'string',
					'enum'        => [ 'open', 'closed' ],
				],
				'menu_order'     => [
					'description' => __( 'The order of the object in relation to other object of its type.', 'sst' ),
					'type'        => 'integer',
				],
				'format'         => [
					'description' => __( 'The format for the object.', 'sst' ),
					'type'        => 'string',
					'enum'        => array_values( get_post_format_slugs() ),
				],
				'meta'           => [
					'description'          => __( 'Meta fields.', 'sst' ),
					'type'                 => 'object',
					'additionalProperties' => false,
					'patternProperties'    => [
						'^.*$' => [
							'anyOf' => [
								[
									'type' => 'string',
								],
								[
									'type' => 'array',
								],
							],
						],
					],
					'properties'           => [
						'sst_source_id' => [
							'type'        => 'string',
							'description' => __( 'The original source ID.', 'sst' ),
							'required'    => true,
						],
					],
					'arg_options'          => [
						'sanitize_callback' => null,
						'validate_callback' => [ $this, 'check_meta_is_array' ],
					],
				],
				'attachments'    => [
					'description' => __( 'Post attachments to download.', 'sst' ),
					'type'        => 'array',
					'items'       => [
						'type' => 'string',
					],
				],
			],
		];

		$taxonomies = get_taxonomies( [], 'objects' );
		foreach ( $taxonomies as $taxonomy ) {
			$base = ! empty( $taxonomy->rest_base ) ? $taxonomy->rest_base : $taxonomy->name;

			$schema['properties'][ $base ] = [
				/* translators: %s: taxonomy name */
				'description' => sprintf( __( 'The terms assigned to the object in the %s taxonomy.', 'sst' ), $taxonomy->name ),
				'type'        => 'array',
				'items'       => [
					'type' => 'integer',
				],
			];
		}

		return $this->add_additional_fields_schema( $schema );
	}

	/**
	 * Check the 'meta' value of a request is an associative array.
	 *
	 * @param  mixed $value The meta value submitted in the request.
	 * @return WP_Error|string The meta array, if valid, otherwise an error.
	 */
	public function check_meta_is_array( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}

		return $value;
	}
}
