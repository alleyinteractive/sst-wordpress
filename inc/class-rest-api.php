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

		$prepared_post = get_post( (int) $id );
		if ( empty( $prepared_post ) || empty( $prepared_post->ID ) ) {
			return $error;
		}

		return $prepared_post;
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
				'post_id'       => $object->ID,
				'post_type'     => $object->post_type,
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
	 * Save an array of post meta to a given post id.
	 *
	 * @param int                    $post_id Post ID.
	 * @param \WP_REST_Request|array $request REST request or array containing
	 *                                        post meta.
	 * @return bool True if meta is added, false if not.
	 */
	protected function save_post_meta( int $post_id, $request ): bool {
		if ( empty( $request['meta'] ) ) {
			return false;
		}

		foreach ( $request['meta'] as $key => $values ) {
			// Discern between single values and multiple.
			if ( is_array( $values ) ) {
				delete_post_meta( $post_id, $key );
				foreach ( $values as $value ) {
					add_post_meta( $post_id, $key, $value );
				}
			} else {
				update_post_meta( $post_id, $key, $values );
			}
		}

		return true;
	}

	/**
	 * Downloads an image from the specified URL and attaches it to a post.
	 *
	 * This is a wrapper for core's `media_sideload_image()`, but it first
	 * ensures that the function exists and if not, loads the requisite files.
	 *
	 * @see \media_sideload_image()
	 *
	 * @param string $file    The URL of the image to download.
	 * @param int    $post_id The post ID the media is to be associated with.
	 * @param string $desc    Optional. Description of the image.
	 * @param string $return  Optional. Accepts 'html' (image tag html) or 'src'
	 *                        (URL), or 'id' (attachment ID). Default 'html'.
	 * @return string|WP_Error Populated HTML img tag on success, WP_Error
	 *                         object otherwise.
	 */
	protected function media_sideload_image( $file, $post_id, $desc = null, $return = 'html' ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		return media_sideload_image( $file, $post_id, $desc, $return );
	}

	/**
	 * Download images to a given post ID for a given REST request.
	 *
	 * @param int              $post_id Post ID to which to attach the images.
	 * @param \WP_REST_Request $request REST API request containing the image
	 *                                  data to download and save.
	 * @return array Array of source urls and resulting ids, or source urls and
	 *               resulting errors.
	 */
	protected function download_images( int $post_id, \WP_REST_Request $request ): array {
		$return = [];

		if ( empty( $request['references'] ) ) {
			return $return;
		}

		foreach ( $request['references'] as $reference ) {
			// Skip this reference if it is not an attachment.
			if (
				'post' !== $reference['type']
				|| 'attachment' !== $reference['subtype']
				|| empty( $reference['args']['url'] )
			) {
				continue;
			}

			$source = $reference['args'];

			// Move the source id to meta.
			$source['meta']['sst_source_id'] = $reference['sst_source_id'];

			// Download the image to WordPress.
			$image_id = $this->media_sideload_image(
				$source['url'],
				$post_id,
				$source['description'] ?? '',
				'id'
			);

			if ( is_wp_error( $image_id ) ) {
				$return[] = [
					'source_url' => $source['url'],
					'error'      => $image_id->get_error_message(),
				];
				continue;
			}
			$image_id = intval( $image_id );

			// Save meta for the image.
			if (
				empty( $source['meta']['_wp_attachment_image_alt'] )
				&& ! empty( $source['description'] )
			) {
				// If the alt text is missing, set it to the description.
				$source['meta']['_wp_attachment_image_alt'] = $source['description'];
			}
			$this->save_post_meta( $image_id, $source );

			$return[] = [
				'sst_source_id' => $source['meta']['sst_source_id'],
				'source_url'    => $source['url'],
				'id'            => $image_id,
			];
		}

		return $return;
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
	 * @param \WP_REST_Request $request Full details about the request.
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
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function create_item( $request ) {
		$created_objects = [
			'errors' => [],
		];

		// Validate the post to be inserted.
		$prepared_post = $this->prepare_item_for_database( $request );
		if ( is_wp_error( $prepared_post ) ) {
			return $prepared_post;
		}

		// Defer to the core REST API endpoint to create the post.
		$post_type_obj = get_post_type_object( $request['type'] );
		$response      = $this->dispatch_request(
			'POST',
			'/wp/v2/' . ( $post_type_obj->rest_base ?: $post_type_obj->name ),
			[ 'body' => $prepared_post ]
		);

		// Confirm the response from the core endpoint.
		if ( is_wp_error( $response ) ) {
			return $response;
		} elseif ( $response->get_status() >= 400 ) {
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

		// Add the created object to this endpoint's response.
		$data = $response->get_data();
		if ( empty( $data['id'] ) ) {
			return new \WP_Error(
				'missing-post-id',
				sprintf(
					__( 'Missing the ID of the created post.', 'sst' ),
					$response->get_status()
				),
				[ 'status' => 400 ]
			);
		}

		$post = get_post( $data['id'] );

		// Save the post meta.
		$this->save_post_meta( $post->ID, $request );

		// Add the created post to the response.
		$created_objects = $this->add_object_to_response(
			$created_objects,
			$post
		);

		// Download images and add to the response.
		$attachments = $this->download_images( $post->ID, $request );
		foreach ( $attachments as $attachment ) {
			if ( ! empty( $attachment['id'] ) ) {
				$created_objects = $this->add_object_to_response(
					$created_objects,
					get_post( $attachment['id'] )
				);
			} elseif ( ! empty( $attachment['error'] ) ) {
				$created_objects['errors'][] = $attachment;
			}
		}

		// Set the API response.
		$api_response = rest_ensure_response( $created_objects );
		$api_response->set_status( 201 );
		return $api_response;
	}

	/**
	 * Checks if a given request has access to update a post.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to update the item, WP_Error object otherwise.
	 */
	public function update_item_permissions_check( $request ) {
		$prepared_post = $this->get_post( $request['id'] );
		if ( is_wp_error( $prepared_post ) ) {
			return $prepared_post;
		}

		return $this->authenticate_sst_permissions_check();
	}

	/**
	 * Updates a single post.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
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
				__( 'Post is missing source ID (`meta.sst_source_id`)', 'sst' ),
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
		unset(
			// Attachments will be downloaded separately.
			$post_data['references'],
			// Post type is used to determine which core endpoint gets dispatched.
			$post_data['type'],
			// Meta is saved separately to allow for unregistered meta.
			$post_data['meta']
		);

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
							'post_id'       => [
								'type'        => 'integer',
								'description' => __( 'The WordPress post ID.', 'sst' ),
							],
							'post_type'     => [
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
		$available_post_types = array_values( get_post_types() );
		$taxonomies           = get_taxonomies( [], 'objects' );

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
					'enum'        => $available_post_types,
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
					'additionalProperties' => true,
					'properties'           => [
						'sst_source_id' => [
							'type'        => 'string',
							'description' => __( 'The original source ID.', 'sst' ),
							'required'    => true,
						],
					],
					'arg_options'          => [
						'sanitize_callback' => [ $this, 'sanitize_meta' ],
						'validate_callback' => [ $this, 'validate_meta' ],
					],
				],
				'references'     => [
					'description' => __( 'Post references to create.', 'sst' ),
					'type'        => 'array',
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'type'          => [
								'description' => __( 'The object type.', 'sst' ),
								'type'        => 'string',
								'required'    => true,
								'enum'        => [
									'post',
									'term',
								],
							],
							'subtype'       => [
								'description' => __( 'The object subtype (post type or taxonomy).', 'sst' ),
								'type'        => 'string',
								'required'    => true,
								'enum'        => array_merge(
									$available_post_types,
									array_keys( $taxonomies )
								),
							],
							'sst_source_id' => [
								'description' => __( 'The original source ID.', 'sst' ),
								'type'        => 'string',
								'required'    => true,
							],
							'args'          => [
								'description' => __( 'Arguments for creating the reference.', 'sst' ),
								'type'        => 'object',
								'properties'  => [
									'url'         => [
										'description' => __( 'The URL for an attachment, if this reference is an attachment.', 'sst' ),
										'type'        => 'string',
									],
									'description' => [
										'description' => __( 'The image description (used as alt text), if this reference is an attachment.', 'sst' ),
										'type'        => 'string',
									],
									'meta'        => [
										'description' => __( 'Meta to add to posts or terms created.', 'sst' ),
										'type'        => 'object',
										'arg_options' => [
											'sanitize_callback' => [ $this, 'sanitize_meta' ],
											'validate_callback' => [ $this, 'validate_meta' ],
										],
									],
								],
							],
						],
					],
					'arg_options' => [
						// 'sanitize_callback' => [ $this, 'sanitize_references' ],
						'validate_callback' => [ $this, 'validate_references' ],
					],
				],
			],
		];

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
	 * Check the 'meta' value of a request is in the proper format.
	 *
	 * @param mixed $values The meta value submitted in the request.
	 * @return WP_Error|string The meta array, if valid, otherwise an error.
	 */
	public function validate_meta( $values ) {
		if ( ! is_array( $values ) ) {
			return false;
		}

		foreach ( $values as $meta_key => $meta_value ) {
			if ( ! is_string( $meta_key ) ) {
				return new \WP_Error(
					'sst-invalid-meta-key',
					/* translators: 1: meta key */
					sprintf( __( 'Invalid meta key %1$s. Meta keys must be strings.', 'sst' ), $meta_key )
				);
			}
			if ( ! is_scalar( $meta_value ) && ! wp_is_numeric_array( $meta_value ) ) {
				return new \WP_Error(
					'sst-invalid-meta-value',
					/* translators: 1: meta key */
					sprintf( __( 'Invalid meta value for key %1$s. Meta values must either be numeric arrays or scalar values.', 'sst' ), $meta_key )
				);
			}
			if ( is_array( $meta_value ) ) {
				foreach ( $meta_value as $individual_value ) {
					if ( ! is_scalar( $individual_value ) ) {
						return new \WP_Error(
							'sst-invalid-meta-value',
							/* translators: 1: meta key */
							sprintf( __( 'Invalid meta value for key %1$s. Meta values within arrays must be scalar values.', 'sst' ), $meta_key )
						);
					}
				}
			}
		}

		return true;
	}

	/**
	 * Sanitize the meta array for a post.
	 *
	 * @param array            $values  The meta array.
	 * @param \WP_REST_Request $request The request object.
	 * @param string           $param   The parameter name.
	 * @return array
	 */
	public function sanitize_meta( $values, $request, $param ) {
		// Run a basic pass against the schema.
		$values = rest_parse_request_arg( $values, $request, $param );

		// Run a deeper sanitization of the formats of individual meta.
		foreach ( $values as &$meta_value ) {
			if ( is_scalar( $meta_value ) ) {
				$meta_value = strval( $meta_value );
			} else {
				foreach ( $meta_value as &$individual_value ) {
					$individual_value = strval( $individual_value );
				}
			}
		}

		return $values;
	}

	/**
	 * Check that the references in a request is in the proper format.
	 *
	 * @param mixed            $values  The meta value submitted in the request.
	 * @param \WP_REST_Request $request The request object.
	 * @param string           $param   The parameter name.
	 * @return WP_Error|string The meta array, if valid, otherwise an error.
	 */
	public function validate_references( $values, $request, $param ) {
		$schema_validation = rest_validate_request_arg( $values, $request, $param );
		if ( true !== $schema_validation ) {
			return $schema_validation;
		}

		foreach ( $values as $ref ) {
			if (
				empty( $ref['type'] )
				|| empty( $ref['subtype'] )
				|| empty( $ref['sst_source_id'] )
			) {
				return new \WP_Error(
					'sst-invalid-reference',
					__( 'Invalid reference; type, subtype, and sst_source_id are required properties.', 'sst' )
				);
			}
		}

		return true;
	}
}
