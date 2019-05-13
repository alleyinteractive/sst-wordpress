<?php
/**
 * Custom Content Types for SST
 *
 * @package SST
 */

namespace SST;

add_action( 'init', __NAMESPACE__ . '\register_promise_post_type', 999999 );

/**
 * Register the sst-promise post type.
 */
function register_promise_post_type() {
	$promise_args = [
		'public'       => false,
		'hierarchical' => true,
		'taxonomies'   => array_keys( get_taxonomies() ),
		'can_export'   => false,
		'show_in_rest' => true,
		'rewrite'      => false,
	];
	register_post_type( 'sst-promise', $promise_args );
}
