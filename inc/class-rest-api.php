<?php
/**
 * REST API Integration
 *
 * @package SST
 */

namespace SST;

use stdClass;
use WP_Error;
use WP_Post;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Server;
use WP_Term;
use function Network_Media_Library\switch_to_media_site;

/**
 * REST API class for SST.
 */
class REST_API extends WP_REST_Controller {

	/**
	 * Refs created by the request.
	 *
	 * @var array
	 */
	protected $created_refs = [];

	/**
	 * Non-fatal errors.
	 *
	 * @var array
	 */
	protected $errors = [];

	/**
	 * Store the objects created during the request for the API response.
	 *
	 * @var array
	 */
	protected $response_objects = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'sst/v1';
		$this->rest_base = 'post';

		// If this looks to be an SST request, run early hooks.
		if (
			! empty( $_SERVER['REQUEST_URI'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			&& false !== strpos( $_SERVER['REQUEST_URI'], "{$this->namespace}/{$this->rest_base}" )
		) {
			$this->early_sst_hooks();
		}
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
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'create_item_permissions_check' ],
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::CREATABLE ),
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
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_item' ],
					'permission_callback' => [ $this, 'update_item_permissions_check' ],
					'args'                => $this->get_endpoint_args_for_item_schema( WP_REST_Server::EDITABLE ),
				],
				'schema' => [ $this, 'get_response_schema' ],
			]
		);
	}

	/**
	 * Add hooks for modifying data or functionality during SST requests, before
	 * the request has passed through the REST API framework. For instance, if
	 * something needs to happen on or before `init`.
	 */
	public function early_sst_hooks() {
		// Set WP_IMPORTING to hopefully improve general compatibility.
		defined( 'WP_IMPORTING' ) || define( 'WP_IMPORTING', true );

		// Don't let Jetpack try to send sync requests during SST requests.
		add_filter( 'jetpack_sync_sender_should_load', '__return_false', 999999 );

		// Prevent AMP from loading.
		remove_action( 'init', 'amp_init', 0 );

		// Fire early hook.
		do_action( 'sst_on_early' );
	}

	/**
	 * Add filters for modifying core data or functionality, but only during SST
	 * REST requests.
	 */
	public function add_sst_request_filters() {
		// Allow requests to set the modified date.
		foreach ( get_post_types( [ 'show_in_rest' => true ] ) as $post_type ) {
			add_filter( "rest_pre_insert_{$post_type}", [ $this, 'set_modified' ], 10, 2 );
		}
		add_filter( 'wp_insert_post_data', [ $this, 'prevent_updates_from_overwriting_modified_date' ], 5, 2 );
		add_filter( 'wp_insert_post_data', [ $this, 'set_modified_for_real' ], 10, 2 );

		// Address bug in core with long attachment filenames.
		add_filter( 'wp_insert_attachment_data', [ $this, 'filter_attachment_guid' ] );

		// Don't schedule async publishing actions.
		add_filter( 'wpcom_async_transition_post_status_should_offload', '__return_false' );
		add_filter( 'wpcom_async_transition_post_status_schedule_async', '__return_false' );

		// Disable Jetpack Publicize during SST requests.
		add_filter( 'wpas_submit_post?', '__return_false' );

		// Disable pixel tracking of uploads.
		remove_filter( 'wp_handle_upload', '\Automattic\VIP\Stats\handle_file_upload', 9999 );

		// Disable pings and stuff.
		remove_action( 'publish_post', '_publish_post_hook', 5 );

		// Disable revisions.
		add_filter( 'wp_revisions_to_keep', '__return_zero', 99 );

		// Disable AMP validation during SST requests.
		if ( class_exists( 'AMP_Validation_Manager' ) ) {
			remove_action( 'shutdown', [ AMP_Validation_Manager::class, 'validate_queued_posts_on_frontend' ] );
		}
	}

	/**
	 * Allow modified and modified_gmt properties to be set for SST-created
	 * post objects.
	 *
	 * @param stdClass        $prepared_post An object representing a single
	 *                                       post prepared for inserting or
	 *                                       updating the database.
	 * @param WP_REST_Request $request       Request object.
	 * @return stdClass
	 */
	public function set_modified( $prepared_post, WP_REST_Request $request ) {
		if ( is_wp_error( $prepared_post ) ) {
			return $prepared_post;
		}

		if ( ! empty( $request['modified'] ) ) {
			$date_data = rest_get_date_with_gmt( $request['modified'] );
		} elseif ( ! empty( $request['modified_gmt'] ) ) {
			$date_data = rest_get_date_with_gmt( $request['modified_gmt'], true );
		}

		if ( ! empty( $date_data ) ) {
			list( $prepared_post->post_modified, $prepared_post->post_modified_gmt ) = $date_data;
		}

		return $prepared_post;
	}

	/**
	 * Edit the data being saved for a post to ensure that if the post_modified
	 * and/or post_modified_gmt dates are explicitly set, that they get saved.
	 * Core doesn't support this out-of-the-box. This accompanies
	 * {@see REST_API::set_modified()}.
	 *
	 * @param array $data    An array of slashed post data.
	 * @param array $postarr An array of sanitized, but otherwise unmodified
	 *                       post data.
	 * @return array
	 */
	public function set_modified_for_real( array $data, array $postarr ): array {
		$modified     = false;
		$modified_gmt = false;

		// Store the local timestamp if set.
		if (
			! empty( $postarr['post_modified'] )
			&& $this->is_valid_date( $postarr['post_modified'] )
		) {
			$modified = $postarr['post_modified'];
		}

		// Store the GMT timestamp if set.
		if (
			! empty( $postarr['post_modified_gmt'] )
			&& $this->is_valid_date( $postarr['post_modified_gmt'] )
		) {
			$modified_gmt = $postarr['post_modified_gmt'];
		}

		// Ensure that both the local and gmt timestamps get set if either is.
		if ( $modified && ! $modified_gmt ) {
			$modified_gmt = get_gmt_from_date( $modified );
		} elseif ( $modified_gmt && ! $modified ) {
			$modified = get_date_from_gmt( $modified_gmt );
		}

		if ( $modified && $modified_gmt ) {
			$data['post_modified']     = $modified;
			$data['post_modified_gmt'] = $modified_gmt;
		}

		return $data;
	}

	/**
	 * Ensure that post updates made during SST requests don't alter the
	 * modified datetimes unless explicitly intended.
	 *
	 * @param array $data    An array of slashed post data.
	 * @param array $postarr An array of sanitized, but otherwise unmodified
	 *                       post data.
	 * @return array
	 */
	public function prevent_updates_from_overwriting_modified_date( array $data, array $postarr ): array {
		if (
			empty( $postarr['post_modified'] )
			&& empty( $postarr['post_modified_gmt'] )
		) {
			unset( $data['post_modified'], $data['post_modified_gmt'] );
		}

		return $data;
	}

	/**
	 * Confirm that a MySQL datetime string is valid.
	 *
	 * @param string $date MySQL-formatted datetime string (Y-m-d H:i:s).
	 * @return bool True if valid, false if not.
	 */
	protected function is_valid_date( string $date ): bool {
		$format = 'Y-m-d H:i:s';
		$d      = date_create_from_format( $format, $date );
		return ( $d && $d->format( $format ) === $date );
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
		$error = new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'sst' ), [ 'status' => 404 ] );
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
		$request = new WP_REST_Request( $method, $route );
		$request->add_header( 'content-type', 'application/json' );

		if ( ! empty( $args['body'] ) ) {
			$request->set_body( wp_json_encode( $args['body'] ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * Record a non-fatal error to add to the response.
	 *
	 * @param WP_Error $error Non-fatal error.
	 */
	protected function add_nonfatal_error( WP_Error $error ) {
		$this->errors[] = $error->get_error_message();
	}

	/**
	 * Add a created object to the response.
	 *
	 * @param WP_Post|WP_Term $object Post or term object.
	 * @return bool True on success, false on failure.
	 */
	public function add_object_to_response( $object ): bool {
		if ( $object instanceof WP_Post ) {
			$this->response_objects['posts'][] = [
				'post_id'       => $object->ID,
				'post_type'     => $object->post_type,
				'sst_source_id' => get_post_meta( $object->ID, 'sst_source_id', true ),
			];

			return true;
		} elseif ( $object instanceof WP_Term ) {
			$this->response_objects['terms'][] = [
				'term_id'  => $object->term_id,
				'taxonomy' => $object->taxonomy,
				'name'     => $object->name,
			];

			return true;
		}

		return false;
	}

	/**
	 * Replace refs recursively.
	 *
	 * @param array  $nested_meta Array to be replaced.
	 * @param string $path Path being replaced.
	 * @return array Replaced nested meta.
	 */
	protected function recursive_refs_replace( array $nested_meta, string $path ) {
		$replaced_nested_meta = [];
		foreach ( $nested_meta as $key => $value ) {
			$new_path = $path ? "{$path}.{$key}" : $key;
			if ( is_array( $value ) ) {
				$replaced_nested_meta[ $key ] = $this->recursive_refs_replace(
					$value,
					$new_path
				);
			} elseif ( is_string( $value ) ) {
				$replaced_nested_meta[ $key ] = $this->replace_refs_in_meta_value(
					$value,
					$new_path
				);
			} else {
				$replaced_nested_meta[ $key ] = $value;
			}
		}
		return $replaced_nested_meta;
	}

	/**
	 * Replace references in nested meta.
	 *
	 * @param array $nested_meta Nested meta for replacement.
	 * @return array Replaced nested meta.
	 */
	protected function replace_refs_in_nested_meta( array $nested_meta ) {
		return $this->recursive_refs_replace( $nested_meta, '' );
	}

	/**
	 * Save an array of post meta to a given post id.
	 *
	 * @param int                   $post_id Post ID.
	 * @param WP_REST_Request|array $request REST request or array containing
	 *                                        post meta.
	 * @return bool True if meta is added, false if not.
	 */
	protected function save_post_meta( int $post_id, $request ): bool {
		/**
		 * Filter the post meta to be saved prior to saving it.
		 *
		 * @param int                   $meta    Array of post meta, as
		 *                                       `key => value(s)` pairs.
		 * @param int                   $post_id Post ID.
		 * @param WP_REST_Request|array $request REST request or array
		 *                                       containing post meta.
		 */
		$meta = apply_filters(
			'sst_pre_save_post_meta',
			$request['meta'] ?? [],
			$post_id,
			$request
		);

		$nested_meta = apply_filters(
			'sst_pre_save_post_meta_nested',
			$request['nestedMeta'] ?? [],
			$post_id,
			$request
		);

		if ( empty( $meta ) && empty( $nested_meta ) ) {
			return false;
		}

		if ( ! empty( $meta ) ) {
			foreach ( $meta as $key => $values ) {
				// Discern between single values and multiple.
				if ( is_array( $values ) ) {
					delete_post_meta( $post_id, $key );
					foreach ( $values as $value ) {
						add_post_meta(
							$post_id,
							$key,
							$this->replace_refs_in_meta_value( $value, $key )
						);
					}
				} else {
					update_post_meta(
						$post_id,
						$key,
						$this->replace_refs_in_meta_value( (string) $values, $key )
					);
				}
			}
		}

		if ( ! empty( $nested_meta ) ) {
			$nested_meta = $this->replace_refs_in_nested_meta( $nested_meta );
			foreach ( array_keys( $nested_meta ) as $key ) {
				update_post_meta( $post_id, $key, $nested_meta[ $key ] );
			}
		}

		return true;
	}

	/**
	 * Save an array of term meta to a given term id.
	 *
	 * @param int                   $term_id Term ID.
	 * @param WP_REST_Request|array $request REST request or array containing
	 *                                        term meta.
	 * @return bool True if meta is added, false if not.
	 */
	protected function save_term_meta( int $term_id, $request ): bool {
		/**
		 * Filter the term meta to be saved prior to saving it.
		 *
		 * @param int                   $meta    Array of term meta, as
		 *                                       `key => value(s)` pairs.
		 * @param int                   $term_id Term ID.
		 * @param WP_REST_Request|array $request REST request or array
		 *                                       containing term meta.
		 */
		$meta = apply_filters(
			'sst_pre_save_term_meta',
			$request['meta'] ?? [],
			$term_id,
			$request
		);
		$nested_meta = apply_filters(
			'sst_pre_save_term_meta_nested',
			$request['nestedMeta'] ?? [],
			$term_id,
			$request
		);

		if ( empty( $meta ) && empty( $nested_meta ) ) {
			return false;
		}

		if ( ! empty( $meta ) ) {
			foreach ( $meta as $key => $values ) {
				// Discern between single values and multiple.
				if ( is_array( $values ) ) {
					delete_term_meta( $term_id, $key );
					foreach ( $values as $value ) {
						add_term_meta(
							$term_id,
							$key,
							$this->replace_refs_in_meta_value( $value, $key )
						);
					}
				} else {
					update_term_meta(
						$term_id,
						$key,
						$this->replace_refs_in_meta_value( $values, $key )
					);
				}
			}
		}

		if ( ! empty( $nested_meta ) ) {
			$nested_meta = $this->replace_refs_in_nested_meta( $nested_meta );
			foreach ( array_keys( $nested_meta ) as $key ) {
				update_term_meta(
					$term_id,
					$key,
					$nested_meta[ $key ]
				);
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
	public static function media_sideload_image( $file, $post_id, $desc = null, $return = 'html' ) {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		switch_to_media_site();
		$sideload = media_sideload_image( $file, $post_id, $desc, $return );
		restore_current_blog();
		return $sideload;
	}

	/**
	 * Downloads a file from the specified URL and attaches it to a post.
	 *
	 * This method is a clone of media_sideload_image without the image
	 * extension validation. In place of that, this uses `wp_check_filetype()`.
	 * To further improve security, this also disables `unfiltered_upload`, so
	 * even for super admins, the downloaded file will be validated against the
	 * allowed types in this WordPress install.
	 *
	 * @param string $file    The URL of the file to download.
	 * @param int    $post_id The post ID the media is to be associated with.
	 * @param string $desc    Optional. Description of the file.
	 * @return int|WP_Error Attachment ID on success, WP_Error otherwise.
	 */
	public static function media_sideload_file( $file, $post_id, $desc = null ) {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		if ( ! empty( $file ) ) {
			// Only let this method download files that a non-admin can upload.
			add_filter( 'user_has_cap', [ __CLASS__, 'disable_unfiltered_upload' ] );

			// Pull out the filename from the URL.
			$parsed_url = wp_parse_url( $file );
			if ( empty( $parsed_url['path'] ) ) {
				return new WP_Error( 'invalid-url', __( 'Invalid attachment URL to download.', 'sst' ) );
			}

			// Run an early check against allowed file types.
			$file_data = wp_check_filetype( $parsed_url['path'] );
			if ( ! $file_data['ext'] && ! $file_data['type'] ) {
				return new WP_Error(
					'invalid-file-type',
					sprintf(
						/* translators: %s: filename */
						__( 'Attachment file type is not allowed for `%s`.', 'sst' ),
						wp_basename( $parsed_url['path'] )
					)
				);
			}

			$file_array         = [];
			$file_array['name'] = wp_basename( $parsed_url['path'] );

			// Download file to temp location.
			$file_array['tmp_name'] = static::_handle_file_download( $file );

			// Resume normal functioning of `unfiltered_upload`.
			remove_filter( 'user_has_cap', [ __CLASS__, 'disable_unfiltered_upload' ] );

			// If error storing temporarily, return the error.
			if ( is_wp_error( $file_array['tmp_name'] ) ) {
				return $file_array['tmp_name'];
			}

			// Do the validation and storage stuff.
			switch_to_media_site();
			$id = static::media_handle_sideload( $file_array, $post_id, $desc, [] );
			restore_current_blog();

			// If error storing permanently, unlink.
			if ( is_wp_error( $id ) ) {
				if ( file_exists( $file_array['tmp_name'] ) ) {
					// Unlink may throw a warning beyond our control, silence!
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					@unlink( $file_array['tmp_name'] );
				}
			}

			return $id;
		}
	}

	/**
	 * Download a file.
	 *
	 * @param string $url URL to download.
	 * @return mixed WP_Error on failure, string Filename on success.
	 */
	protected static function _handle_file_download( $url ) {
		/**
		 * Filter the downloading of a file.
		 *
		 * @param bool|string $file File name, defaults to false.
		 * @param string      $url URL to download.
		 * @return bool|string
		 */
		$pre_url = apply_filters( 'sst_pre_handle_file_download', false, $url );

		if ( false !== $pre_url ) {
			return $pre_url;
		}

		return download_url( $url );
	}

	/**
	 * Wrapper for media_handle_sideload().
	 *
	 * @see media_handle_sideload()
	 */
	protected static function media_handle_sideload( ...$args ) {
		$filter_args = $args;
		array_unshift( $filter_args, null );

		$id = apply_filters_ref_array( 'sst_pre_media_handle_sideload', $filter_args );
		if ( null !== $id ) {
			return $id;
		}

		return media_handle_sideload( ...$args );
	}

	/**
	 * Dynamically filter a user's capabilities to remove unfiltered_upload.
	 *
	 * @param array $allcaps An array of all the user's capabilities.
	 * @return array
	 */
	public static function disable_unfiltered_upload( $allcaps ) {
		$allcaps['unfiltered_upload'] = false;
		return $allcaps;
	}

	/**
	 * Download file to a given post ID for a given reference.
	 *
	 * @param int   $post_id   Post ID to which to attach the file.
	 * @param array $reference References array entry.
	 * @return WP_Error|WP_Post Post object on success, WP_Error on failure.
	 */
	protected function download_file( int $post_id, array $reference ) {
		$source    = $reference['args'];
		$source_id = $reference['sst_source_id'];

		// Check for existing attachment matching this ID.
		switch_to_media_site();
		$attachment = get_posts(
			[
				'post_type'        => 'attachment',
				'post_status'      => 'any',
				'meta_query'       => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'   => 'sst_source_id',
						'value' => $source_id,
					],
				],
				'orderby'          => 'ID',
				'order'            => 'DESC',
				'suppress_filters' => false,
			]
		);

		if ( ! empty( $attachment[0] ) ) {
			// Update the attachment.
			$source['meta']['sst_source_id'] = $source_id;
			$this->save_post_meta( $attachment[0]->ID, $source );

			// Add the existing attachment  to the response.
			$this->add_object_to_response( $attachment[0] );

			// Store the existing attachment ref for use later.
			$this->created_refs[ $source_id ] = [
				'id'     => $attachment[0]->ID,
				'object' => $attachment[0],
			];

			// Get out of the media site.
			restore_current_blog();

			return $attachment[0];
		}

		/**
		 * Filter the attachment before a file is downloaded.
		 *
		 * Passing in a WP_Post object will use that attachment instead.
		 *
		 * @param int|null $attachment_id Attachment ID, null by default.
		 * @param array $source SST Source.
		 */
		$attachment = apply_filters( 'sst_pre_download_file', null, $source );

		if ( ! empty( $attachment ) && $attachment instanceof WP_Post ) {
			// Add the existing attachment  to the response.
			$this->add_object_to_response( $attachment );

			// Store the existing attachment ref for use later.
			$this->created_refs[ $source_id ] = [
				'id'     => $attachment->ID,
				'object' => $attachment,
			];

			// Get out of the media site.
			restore_current_blog();

			return $attachment;
		}

		// Move the source id to meta.
		$source['meta']['sst_source_id'] = $source_id;

		// SST might send us the WP ID of the ref.
		// Perform a basic check to ensure the ID is valid.
		if (
			! empty( $reference['id'] ) &&
			is_string( get_post_status( $reference['id'] ) )
		) {
			$attachment_arr = [
				'ID'         => $reference['id'],
				'post_title' => $source['title'] ?? $source_id,
			];

			$attachment_id = wp_update_post( $attachment_arr );
		} else {
			// Download the file to WordPress.
			$attachment_id = $this->media_sideload_file(
				$source['url'],
				$post_id,
				! empty( $source['title'] ) ? $source['title'] : null
			);
		}

		// Add a filter to modify the download URL (if failed).
		$attachment_id = apply_filters(
			'sst_download_file_ref',
			$attachment_id,
			$source['url'],
			$post_id,
			! empty( $source['title'] ) ? $source['title'] : null
		);

		if ( is_wp_error( $attachment_id ) ) {
			// Restore the site before returning.
			restore_current_blog();

			return $attachment_id;
		}

		// If this is an image, make some special accommodations.
		if (
			empty( $source['meta']['_wp_attachment_image_alt'] )
			&& ! empty( $source['title'] )
		) {
			$mime = get_post_mime_type( $attachment_id );
			if ( 'image/' === substr( $mime, 0, 6 ) ) {
				// If the alt text is missing, set it to the title.
				$source['meta']['_wp_attachment_image_alt'] = $source['title'];
			}
		}

		// Save meta for the attachment.
		$this->save_post_meta( $attachment_id, $source );

		$post = get_post( $attachment_id );

		// Add the object to the response.
		$this->add_object_to_response( $post );

		// Store the created ref for use later.
		$this->created_refs[ $source_id ] = [
			'id'     => $attachment_id,
			'object' => $post,
		];

		// Once we're done with media, switch back.
		restore_current_blog();

		return $post;
	}

	/**
	 * Create a reference post.
	 *
	 * @param array $reference References array entry.
	 * @return WP_Error|WP_Post Post object on success, WP_Error on failure.
	 */
	protected function create_ref_post( array $reference ) {
		$source    = $reference['args'];
		$source_id = $reference['sst_source_id'];

		// Don't create the post if we've already done so during this request.
		if ( ! empty( $this->created_refs[ $source_id ] ) ) {
			return $this->created_refs[ $source_id ]['object'];
		}

		/**
		 * Allow the reference post creation to be overridden.
		 */
		$pre_create_ref = apply_filters( 'sst_pre_create_ref_post', null, $reference, $this );
		if ( null !== $pre_create_ref ) {
			return $pre_create_ref;
		}

		// Move the source id to meta.
		$source['meta']['sst_source_id'] = $source_id;

		// SST might send us the WP ID of the ref.
		// Perform a basic check to ensure the ID is valid.
		if (
			! empty( $reference['id'] )
			&& is_string( get_post_status( $reference['id'] ) )
			&& \get_post_meta( $reference['id'], 'sst_source_id', true ) === $source_id
		) {
			$post_arr = [
				'ID'          => $reference['id'],
				'post_title'  => $source['title'] ?? $source_id,
				'post_type'   => $reference['subtype'],
				'post_status' => $reference['post_status'] ?? 'draft',
			];

			$post_id = wp_update_post( $post_arr, true );
		} else {
			// Do a quick check to see if the reference already exists.
			// Note: this could have some bad performance, maybe this can be optimized and requested all together.
			$existing_refs = get_posts(
				[
					'post_type'        => $reference['subtype'],
					'post_status'      => 'any',
					'meta_query'       => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						[
							'key'   => 'sst_source_id',
							'value' => $source_id,
						],
					],
					'fields'           => 'ids',
					'orderby'          => 'ID',
					'order'            => 'DESC',
					'posts_per_page'   => 1,
					'suppress_filters' => false,
				]
			);

			// Update the existing reference if it was found.
			if ( ! empty( $existing_refs ) ) {
				$post_arr = [
					'ID'          => $existing_refs[0],
					'post_title'  => $source['title'] ?? $source_id,
					'post_type'   => $reference['subtype'],
					'post_status' => $reference['post_status'] ?? 'draft',
				];

				$post_id = wp_update_post( $post_arr, true );
			} else {
				$post_arr = [
					'post_title'  => $source['title'] ?? $source_id,
					'post_type'   => $reference['subtype'],
					'post_status' => $reference['post_status'] ?? 'draft',
				];

				$post_id = wp_insert_post( $post_arr, true );
			}
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Save meta for the post.
		$this->save_post_meta( $post_id, $source );

		$post = get_post( $post_id );

		// Add the object to the response.
		$this->add_object_to_response( $post );

		// Store the created ref for use later.
		$this->created_refs[ $source_id ] = [
			'id'     => $post_id,
			'object' => $post,
		];

		return $post;
	}

	/**
	 * Create a reference term.
	 *
	 * @param array $reference References array entry.
	 * @param int   $post_id   Post ID to which to attach the term.
	 * @return WP_Error|WP_Term Term object on success, WP_Error on failure.
	 */
	protected function create_ref_term( array $reference, int $post_id ) {
		$source = $reference['args'];

		if (
			empty( $reference['sst_source_id'] )
			&& empty( $source['title'] )
			&& empty( $source['slug'] )
		) {
			return new WP_Error(
				'invalid-reference-term',
				__( 'Terms must contain at least a title, slug, or sst_source_id.', 'sst' )
			);
		}

		$name     = $source['title'] ?? $source['slug'] ?? $reference['sst_source_id'];
		$taxonomy = $reference['subtype'];

		// Move the source id to meta.
		if ( ! empty( $reference['sst_source_id'] ) ) {
			$source_id                       = $reference['sst_source_id'];
			$source['meta']['sst_source_id'] = $source_id;
		} else {
			$source_id = $name;
		}

		// Don't create the term if we've already done so during this request.
		if ( ! empty( $this->created_refs[ $source_id ] ) ) {
			return $this->created_refs[ $source_id ]['object'];
		}

		// Allow for setting the parent and the slug.
		$args = [];
		if ( ! empty( $source['parent'] ) ) {
			if ( is_array( $source['parent'] ) ) {
				$parent = $this->create_ref_term(
					[
						'type'    => 'term',
						'subtype' => $reference['subtype'],
						'args'    => $source['parent'],
					],
					$post_id
				);
				if ( is_wp_error( $parent ) ) {
					return $parent;
				}
				$args['parent'] = $parent->term_id;
			} elseif ( is_int( $source['parent'] ) ) {
				$args['parent'] = $source['parent'];
			}
		}
		if ( ! empty( $source['description'] ) ) {
			$args['description'] = $source['description'];
		}
		if ( ! empty( $source['slug'] ) ) {
			$args['slug'] = $source['slug'];
		}

		$term = wp_insert_term( $name, $taxonomy, $args );
		if ( is_wp_error( $term ) ) {
			return $term;
		}
		$term_id = $term['term_id'];

		// Set the term to the post.
		$result = wp_set_object_terms( $post_id, $term_id, $taxonomy, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Save meta for the term.
		$this->save_term_meta( $term_id, $source );

		$term = get_term( $term_id );

		// Add the object to the response.
		$this->add_object_to_response( $term );

		// Store the created ref for use later.
		$this->created_refs[ $source_id ] = [
			'id'     => $term_id,
			'object' => $term,
		];

		return $term;
	}

	/**
	 * Create references from a given post ID for a given REST request.
	 *
	 * @param int             $post_id Post ID to which to attach the
	 *                                 references.
	 * @param WP_REST_Request $request REST API request containing the
	 *                                  references to create.
	 */
	protected function create_refs( int $post_id, WP_REST_Request $request ) {
		if ( empty( $request['references'] ) ) {
			return;
		}

		foreach ( $request['references'] as $ref_index => $reference ) {
			/**
			 * Allow external code to short-circuit ref creation.
			 *
			 * @param mixed           $result     If this is anything other than
			 *                                    null, the reference will be
			 *                                    skipped. If the returned value
			 *                                    is an object, it will be
			 *                                    included in the API response
			 *                                    as a created object.
			 * @param array           $reference  References array entry.
			 * @param int             $post_id    Post ID containing the
			 *                                    reference.
			 * @param WP_REST_Request $request    REST API request containing
			 *                                    the references to create.
			 */
			$result = apply_filters(
				'sst_before_create_ref',
				null,
				$reference,
				$post_id,
				$request
			);
			if ( null !== $result ) {
				if ( is_wp_error( $result ) ) {
					$this->add_nonfatal_error( $result );
				} elseif (
					$result instanceof WP_Post
					|| $result instanceof WP_Term
				) {
					// Add the object to the response.
					$this->add_object_to_response( $result );
				}
				continue;
			}

			// Handle attachments separately.
			if (
				'post' === $reference['type']
				&& 'attachment' === $reference['subtype']
			) {
				$result = $this->download_file( $post_id, $reference );
			} elseif ( 'post' === $reference['type'] ) {
				$result = $this->create_ref_post( $reference );
			} elseif ( 'term' === $reference['type'] ) {
				$result = $this->create_ref_term( $reference, $post_id );
			} else {
				$result = new WP_Error(
					'invalid-ref',
					sprintf(
						/* translators: %s: reference type */
						__( 'Invalid reference type `%s`', 'sst' ),
						$reference['type']
					)
				);
			}

			/**
			 * Filter created ref post, term, or resulting error.
			 *
			 * @param object           $result    The created ref object
			 *                                    (WP_Post or WP_Term) or the
			 *                                    resulting WP_Error object.
			 * @param array            $reference References array entry.
			 * @param int              $post_id   Post ID containing the
			 *                                    reference.
			 * @param WP_REST_Request $request   REST API request containing
			 *                                    the references to create.
			 */
			$result = apply_filters(
				'sst_after_create_ref',
				$result,
				$reference,
				$post_id,
				$request
			);

			if ( is_wp_error( $result ) ) {
				$this->add_nonfatal_error(
					new WP_Error(
						'ref-failed',
						sprintf(
							/* translators: 1: reference index, 2: error message */
							__( 'Error creating `references[%1$d]`: %2$s', 'sst' ),
							$ref_index,
							$result->get_error_message()
						)
					)
				);

				do_action( 'sst_after_create_ref_final', $result, $reference, $post_id, $request );
				continue;
			}

			// If `save_to_meta` is set, store the resulting ID in that key.
			if ( ! empty( $reference['save_to_meta'] ) ) {
				$object_id = false;
				if ( $result instanceof WP_Post ) {
					$object_id = $result->ID;
				} elseif ( $result instanceof WP_Term ) {
					$object_id = $result->term_id;
				}
				add_post_meta(
					$post_id,
					$reference['save_to_meta'],
					$object_id,
					true
				);
			}

			// Fire off one final after create ref. action.
			do_action( 'sst_after_create_ref_final', $result, $reference, $post_id, $request );
		}
	}

	/**
	 * Build refs, set post meta, replace refs, and set additional fields for an
	 * update or create request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @param WP_Post         $post    Post object.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function set_additional_data_for_request( WP_REST_Request $request, WP_Post $post ) {
		// Create reference objects.
		$this->create_refs( $post->ID, $request );

		// Replace any refs that might appear in content.
		$this->replace_refs_in_post_content( $post );

		// Save the post meta.
		$this->save_post_meta( $post->ID, $request );

		// Save additional fields added to the request.
		$fields_update = $this->update_additional_fields_for_object( $post, $request );
		if ( is_wp_error( $fields_update ) ) {
			return $fields_update;
		}

		return true;
	}

	/**
	 * Check if the current user is allowed to authenticate SST.
	 */
	protected function authenticate_sst_permissions_check() {
		if ( ! current_user_can( 'authenticate_sst' ) ) {
			return new WP_Error(
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
	 * @return true|WP_Error True if the request has access to create items,
	 *                       WP_Error object otherwise.
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! empty( $request['id'] ) ) {
			return new WP_Error(
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
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error
	 *                                   object on failure.
	 */
	public function create_item( $request ) {
		$this->add_sst_request_filters();

		$this->created_refs     = [];
		$this->errors           = [];
		$this->response_objects = [];

		// Validate the post to be inserted.
		$prepared_post = $this->prepare_item_for_database( $request );
		if ( is_wp_error( $prepared_post ) ) {
			return $prepared_post;
		}

		// Check if there should be a different response entirely.
		$pre_create = apply_filters( 'sst_pre_create_item', null, $prepared_post, $request );
		if ( null !== $pre_create ) {
			return rest_ensure_response( $pre_create );
		}

		// Defer to the core REST API endpoint to create the post.
		$post_type_obj = get_post_type_object( $request['type'] );

		// Allow sst_prepare_post to filter and update an existing post.
		if ( empty( $prepared_post->id ) ) {
			$response = $this->dispatch_request(
				'POST',
				'/wp/v2/' . ( $post_type_obj->rest_base ?: $post_type_obj->name ),
				[ 'body' => $prepared_post ]
			);
		} else {
			$post_id = $prepared_post->id;
			unset( $prepared_post->id );

			$response = $this->dispatch_request(
				'PUT',
				sprintf(
					'/wp/v2/%s/%d',
					( $post_type_obj->rest_base ?: $post_type_obj->name ),
					$post_id
				),
				[ 'body' => $prepared_post ]
			);
		}

		// Confirm the response from the core endpoint.
		if ( is_wp_error( $response ) ) {
			return $response;
		} elseif ( $response->get_status() >= 400 ) {
			return $response;
		} elseif ( 200 !== $response->get_status() && 201 !== $response->get_status() ) {
			return new WP_Error(
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
			return new WP_Error(
				'missing-post-id',
				sprintf(
					__( 'Missing the ID of the created post.', 'sst' ),
					$response->get_status()
				),
				[ 'status' => 400 ]
			);
		}

		$post             = get_post( $data['id'] );
		$addl_data_result = $this->set_additional_data_for_request(
			$request,
			$post
		);
		if ( is_wp_error( $addl_data_result ) ) {
			return $addl_data_result;
		}

		// Add the created post to the beginning of the response.
		$this->response_objects['posts'] = array_merge(
			[
				[
					'post_id'       => $post->ID,
					'post_type'     => $post->post_type,
					'sst_source_id' => get_post_meta( $post->ID, 'sst_source_id', true ),
				],
			],
			$this->response_objects['posts'] ?? []
		);

		// Fire the after save action.
		$this->after_save( $post );

		// Set the API response.
		$api_response = rest_ensure_response(
			array_merge(
				$this->response_objects,
				[
					'errors' => $this->errors,
				]
			)
		);
		$api_response->set_status( 201 );
		return $api_response;
	}

	/**
	 * Checks if a given request has access to update a post.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error True if the request has access to update the item,
	 *                       WP_Error object otherwise.
	 */
	public function update_item_permissions_check( $request ) {
		do_action( 'sst_pre_update_item_permissions_check', $request );
		$prepared_post = $this->get_post( $request['id'] );

		if ( is_wp_error( $prepared_post ) ) {
			return $prepared_post;
		}

		return $this->authenticate_sst_permissions_check();
	}

	/**
	 * Updates a single post.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error
	 *                                   object on failure.
	 */
	public function update_item( $request ) {
		$this->add_sst_request_filters();

		$this->created_refs     = [];
		$this->errors           = [];
		$this->response_objects = [];

		$post_id = $request->get_param( 'id' );

		// Validate the post to be updated.
		$prepared_post = $this->prepare_item_for_database( $request );
		if ( is_wp_error( $prepared_post ) ) {
			return $prepared_post;
		}

		// Check if there should be a different response entirely.
		$pre_update = apply_filters( 'sst_pre_update_item', null, $prepared_post, $request );
		if ( null !== $pre_update ) {
			return rest_ensure_response( $pre_update );
		}

		// Defer to the core REST API endpoint to update the post.
		$post_type_obj = get_post_type_object( $request['type'] );
		$response      = $this->dispatch_request(
			'PUT',
			sprintf(
				'/wp/v2/%s/%d',
				( $post_type_obj->rest_base ?: $post_type_obj->name ),
				$post_id
			),
			[ 'body' => $prepared_post ]
		);

		// Confirm the response from the core endpoint.
		if ( is_wp_error( $response ) ) {
			return $response;
		} elseif ( $response->get_status() >= 400 ) {
			return $response;
		} elseif ( 200 !== $response->get_status() ) {
			return new WP_Error(
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
			return new WP_Error(
				'missing-post-id',
				sprintf(
					__( 'Missing the ID of the updated post in core response.', 'sst' ),
					$response->get_status()
				),
				[ 'status' => 400 ]
			);
		}

		$post = get_post( $data['id'] );

		// Ensure the object is added to the response if updated.
		if ( ! empty( $post ) && $data['id'] !== $post_id ) {
			$this->add_object_to_response( $post );
		}

		$addl_data_result = $this->set_additional_data_for_request(
			$request,
			$post
		);

		if ( is_wp_error( $addl_data_result ) ) {
			return $addl_data_result;
		}

		// Set the API response.
		$api_response = rest_ensure_response(
			array_merge(
				$this->response_objects,
				[
					'errors' => $this->errors,
				]
			)
		);

		// Fire the after save action.
		$this->after_save( $post );

		$api_response->set_status( 200 );
		return $api_response;
	}

	/**
	 * Fire the after save action.
	 *
	 * @param \WP_Post $post Post object.
	 */
	protected function after_save( $post ) {
		/**
		 * Set any additional data for the post.
		 *
		 * @param \WP_Post $post Post object.
		 */
		\do_action( 'sst_after_save', $post );
	}

	/**
	 * Prepares a single post for create or update.
	 *
	 * @since 4.7.0
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return stdClass|WP_Error Post object or WP_Error.
	 */
	protected function prepare_item_for_database( $request ) {
		$post_data = (array) $request->get_json_params();
		$post_type = $post_data['type'];

		// First, check required fields.
		if (
			empty( $request['meta']['sst_source_id'] )
			&& WP_REST_Server::CREATABLE === $request->get_method()
		) {
			return new WP_Error(
				'empty-source_id',
				__( 'Post is missing source ID (`meta.sst_source_id`)', 'sst' ),
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

		/**
		 * Filter any "prepared" post before it gets sent to the core endpoint.
		 *
		 * @param stdClass        $post_data An object representing a single
		 *                                   post prepared for inserting or
		 *                                   updating the database.
		 * @param WP_REST_Request $request   Request object.
		 */
		$post_data = apply_filters(
			'sst_prepare_post',
			(object) $post_data,
			$request
		);

		/**
		 * Filter a "prepared" post before it gets sent to the core endpoint.
		 *
		 * @param stdClass        $post_data An object representing a single
		 *                                   post prepared for inserting or
		 *                                   updating the database.
		 * @param WP_REST_Request $request   Request object.
		 */
		return apply_filters(
			"sst_prepare_post_{$post_type}",
			(object) $post_data,
			$request
		);
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
	 * Retrieves an array of endpoint arguments from the item schema for the controller.
	 *
	 * @param string $method Optional. HTTP method of the request. The arguments
	 *                       for `CREATABLE` requests are checked for required
	 *                       values and may fall-back to a given default, this
	 *                       is not done on `EDITABLE` requests. Default
	 *                       WP_REST_Server::CREATABLE.
	 * @return array Endpoint arguments.
	 */
	public function get_endpoint_args_for_item_schema( $method = WP_REST_Server::CREATABLE ) {
		$endpoint_args = parent::get_endpoint_args_for_item_schema( $method );
		if ( WP_REST_Server::EDITABLE === $method ) {
			// Update requests still require the post type.
			$endpoint_args['type']['required'] = true;
		}
		return $endpoint_args;
	}

	/**
	 * Retrieves the SST schema, primarily intended to be turned into request
	 * args, conforming to JSON Schema.
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema() {
		$rest_post_types = array_values( get_post_types( [ 'show_in_rest' => true ] ) );

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
				'modified'       => [
					'description' => __( "The date the object was last modified, in the site's timezone.", 'sst' ),
					'type'        => 'string',
					'format'      => 'date-time',
				],
				'modified_gmt'   => [
					'description' => __( 'The date the object was last modified, as GMT.', 'sst' ),
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
					'enum'        => $rest_post_types,
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
									array_values( get_post_types() ),
									array_values( get_taxonomies() )
								),
							],
							'sst_source_id' => [
								'description' => __( 'The original source ID. Required if the type is "post" and the subtype is not "attachment", optional otherwise.', 'sst' ),
								'type'        => 'string',
							],
							'save_to_meta'  => [
								'description' => __( 'If set, the ID of the created ref will be stored in the given meta key after it is resolved.', 'sst' ),
								'type'        => 'string',
							],
							'args'          => [
								'description' => __( 'Arguments for creating the reference.', 'sst' ),
								'type'        => 'object',
								'properties'  => [
									'url'   => [
										'description' => __( 'The URL for an attachment, if this reference is an attachment. This is required for attachments.', 'sst' ),
										'type'        => 'string',
									],
									'title' => [
										'description' => __( 'The post or term title, if this is a post or term, or image description (which alt text inherits unless set explicitly) if this is an attachment. This is required for terms.', 'sst' ),
										'type'        => 'string',
									],
									'meta'  => [
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
						'sanitize_callback' => [ $this, 'sanitize_references' ],
						'validate_callback' => [ $this, 'validate_references' ],
					],
				],
			],
		];

		// Build a list of taxonomies available across all REST post types.
		$available_taxonomies = get_object_taxonomies( $rest_post_types, 'objects' );
		foreach ( $available_taxonomies as $taxonomy ) {
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
				return new WP_Error(
					'sst-invalid-meta-key',
					/* translators: 1: meta key */
					sprintf( __( 'Invalid meta key %1$s. Meta keys must be strings.', 'sst' ), $meta_key )
				);
			}
			if ( ! is_scalar( $meta_value ) && ! wp_is_numeric_array( $meta_value ) ) {
				return new WP_Error(
					'sst-invalid-meta-value',
					/* translators: 1: meta key */
					sprintf( __( 'Invalid meta value for key %1$s. Meta values must either be numeric arrays or scalar values.', 'sst' ), $meta_key )
				);
			}
			// if ( is_array( $meta_value ) ) {
			// 	foreach ( $meta_value as $individual_value ) {
			// 		if ( ! is_scalar( $individual_value ) ) {
			// 			return new WP_Error(
			// 				'sst-invalid-meta-value',
			// 				/* translators: 1: meta key */
			// 				sprintf( __( 'Invalid meta value for key %1$s. Meta values within arrays must be scalar values.', 'sst' ), $meta_key )
			// 			);
			// 		}
			// 	}
			// }
		}

		return true;
	}

	/**
	 * Sanitize the meta array for a post.
	 *
	 * @param array           $values  The meta array.
	 * @param WP_REST_Request $request The request object.
	 * @param string          $param   The parameter name.
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
	 * @param mixed           $values  The meta value submitted in the request.
	 * @param WP_REST_Request $request The request object.
	 * @param string          $param   The parameter name.
	 * @return WP_Error|string The meta array, if valid, otherwise an error.
	 */
	public function validate_references( $values, $request, $param ) {
		$schema_validation = rest_validate_request_arg( $values, $request, $param );
		if ( true !== $schema_validation ) {
			return $schema_validation;
		}

		foreach ( $values as $index => $ref ) {
			// Ensure type and subtype are set.
			if (
				empty( $ref['type'] )
				|| empty( $ref['subtype'] )
			) {
				return new WP_Error(
					'sst-invalid-reference',
					sprintf(
						/* translators: %d: reference index */
						__( 'Invalid reference at `references[%d]`; type, subtype, and sst_source_id are required properties.', 'sst' ),
						$index
					)
				);
			}

			// Ensure posts have an sst_source_id.
			if (
				'post' === $ref['type']
				&& 'attachment' !== $ref['subtype']
				&& empty( $ref['sst_source_id'] )
			) {
				return new WP_Error(
					'sst-invalid-reference',
					sprintf(
						/* translators: %d: reference index */
						__( 'Missing required property `references[%d].sst_source_id` for non-attachment post ref.', 'sst' ),
						$index
					)
				);
			}

			// Ensure attachments have urls.
			if (
				'post' === $ref['type']
				&& 'attachment' === $ref['subtype']
				&& empty( $ref['args']['url'] )
			) {
				return new WP_Error(
					'attachment-missing-url',
					sprintf(
						/* translators: %d: reference index */
						__( 'Missing required property `references[%d].args.url`', 'sst' ),
						$index
					)
				);
			}

			// Ensure terms have titles.
			if (
				'term' === $ref['type']
				&& empty( $ref['args']['title'] )
			) {
				return new WP_Error(
					'term-missing-title',
					sprintf(
						/* translators: %d: reference index */
						__( 'Missing required property `references[%d].args.title` for reference term', 'sst' ),
						$index
					)
				);
			}
		}

		return true;
	}

	/**
	 * Sanitize the references array for a post.
	 *
	 * @param array           $values  The references array.
	 * @param WP_REST_Request $request The request object.
	 * @param string          $param   The parameter name.
	 * @return array
	 */
	public function sanitize_references( $values, $request, $param ) {
		// Run a basic pass against the schema.
		$values = rest_sanitize_request_arg( $values, $request, $param );

		// Run a deeper sanitization.
		foreach ( $values as &$ref ) {
			if ( empty( $ref['args'] ) || ! is_array( $ref['args'] ) ) {
				$ref['args'] = [];
			}

			// Ensure that attachments get sst_source_id, defaulting to url.
			if (
				'post' === $ref['type']
				&& 'attachment' === $ref['subtype']
				&& empty( $ref['sst_source_id'] )
			) {
				$ref['sst_source_id'] = $ref['args']['url'];
			}
		}

		return $values;
	}

	/**
	 * Replace refs in post content.
	 *
	 * @param WP_Post $post Post object.
	 */
	protected function replace_refs_in_post_content( WP_Post $post ) {
		// Check the post content to see if any refs need replacement.
		$updated_content = $this->replace_refs_in_string(
			$post->post_content,
			'post_content'
		);

		if (
			! empty( $updated_content )
			&& $updated_content !== $post->post_content
		) {
			wp_update_post(
				[
					'ID'           => $post->ID,
					'post_content' => $updated_content,
				]
			);
		}
	}

	/**
	 * Replace refs in a meta value.
	 *
	 * @param string $value    Meta value.
	 * @param string $meta_key Meta key.
	 * @return string
	 */
	protected function replace_refs_in_meta_value( string $value, string $meta_key ): string {
		// Check the post content to see if any refs need replacement.
		$updated_value = $this->replace_refs_in_string( $value, $meta_key );

		if ( ! empty( $updated_value ) && $updated_value !== $value ) {
			return $updated_value;
		}

		return $value;
	}

	/**
	 * Replace refs in a string.
	 *
	 * The ref format is as follows:
	 *
	 *     {{ <source id> | to <field> }}
	 *
	 * <field> may be one of id or url.
	 *
	 * @param string $value   Value to search for refs.
	 * @param string $context Context in which this replacement is happening.
	 * @return string|null String with replaced content on success, null on
	 *                     failure or if nothing was replaced.
	 */
	protected function replace_refs_in_string( string $value, string $context ) {
		if (
			! empty( $this->created_refs )
			&& preg_match( '/\{\{ .+? \}\}/', $value )
		) {
			return preg_replace_callback(
				'/\{\{ (.*?) \}\}/',
				function ( $matches ) use ( $context ) {
					$data = explode( ' | ', $matches[1] );

					// Ensure at least two segments: source id and what to do with it.
					if ( count( $data ) < 2 ) {
						return '';
					}

					$guid = array_shift( $data );

					// Validate there is something to replace with.
					if ( empty( $this->created_refs[ $guid ]['id'] ) ) {
						return '';
					}

					$ref_id = $this->created_refs[ $guid ]['id'];

					$result  = '';
					$to_type = array_shift( $data );

					// Run the replacement!
					if ( 'to id' === $to_type ) {
						$result = $ref_id;
					} elseif ( 'to url' === $to_type ) {
						// Currently this only supports attachment URLs since we have to switch.
						switch_to_media_site();

						if ( 'attachment' !== get_post_type( $ref_id ) ) {
							$result = get_the_permalink( $ref_id );
						} else {
							if ( wp_attachment_is_image( $ref_id ) ) {
								$size = 'full';
								if (
									! empty( $data[0] )
									&& 'size ' === substr( $data[0], 0, 5 )
								) {
									$size = substr( array_shift( $data ), 5 );
								}

								/**
								 * Filter the image size used when running image
								 * URL replacements.
								 *
								 * @param string $size    Image size.
								 * @param string $context Context in which the image
								 *                        is being used. Either
								 *                        'post_content' or a meta
								 *                        key.
								 */
								$size = apply_filters(
									'sst_image_size_for_replacement',
									$size,
									$context
								);

								$result = wp_get_attachment_image_url(
									$ref_id,
									$size
								);
							} else {
								$result = wp_get_attachment_url( $ref_id );
							}
						}

						restore_current_blog();
					} else {
						/**
						 * Fire on an unknown reference replacement.
						 *
						 * @param mixed  $result The result of the replacement.
						 * @param string $to_type The type of reference to replace (`to field`).
						 * @param int    $ref_id Reference ID.
						 * @param string $context Context in which this replacement is happening.
						 */
						$result = apply_filters( 'sst_replace_ref', $result, $to_type, $ref_id, $context );
					}

					return $result;
				},
				$value
			);
		}

		return null;
	}

	/**
	 * Force a 255-character limit on the GUID of incoming attachment data.
	 *
	 * Intended for the {@see 'wp_insert_attachment_data'} filter.
	 *
	 * @see https://core.trac.wordpress.org/ticket/47296,
	 *      https://core.trac.wordpress.org/ticket/32315.
	 *
	 * @param array $data Sanitized attachment post data.
	 * @return array Updated post data.
	 */
	public function filter_attachment_guid( $data ) {
		if ( ( isset( $data['post_type'] ) && 'attachment' === $data['post_type'] ) && isset( $data['guid'] ) ) {
			$data['guid'] = substr( $data['guid'], 0, 255 );
		}
		return $data;
	}
}
