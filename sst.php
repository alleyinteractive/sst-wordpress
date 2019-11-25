<?php
/**
 * Plugin Name:     SST
 * Plugin URI:      https://github.com/alleyinteractive/sst-wordpress/
 * Description:     SST WordPress Adapter
 * Author:          Matthew Boynes
 * Author URI:      https://www.alley.co/
 * Text Domain:     sst
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package SST
 */

namespace SST;

define( __NAMESPACE__ . '\PATH', __DIR__ );

/**
 * Bootstrap the plugin.
 */
function bootstrap() {
	global $sst_rest;

	// Custom content types.
	require_once PATH . '/inc/content-types.php';

	// Custom capabilities.
	require_once PATH . '/inc/capabilities.php';

	// REST API integration.
	require_once PATH . '/inc/class-rest-api.php';
	$sst_rest = new REST_API();
	add_action( 'rest_api_init', [ $sst_rest, 'register_routes' ] );
	add_action( 'rest_api_init', [ $sst_rest, 'register_meta' ] );
}
add_action( 'after_setup_theme', __NAMESPACE__ . '\bootstrap' );
