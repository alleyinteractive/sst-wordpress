<?php
/**
 * Custom capabilities
 *
 * @package SST
 */

/* phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound */
namespace SST;

add_filter( 'user_has_cap', __NAMESPACE__ . '\custom_capabilities' );

/**
 * Filters a user's capabilities to add custom ones.
 *
 * @param array $allcaps An array of all the user's capabilities.
 * @return array
 */
function custom_capabilities( $allcaps ) {
	// If you can manage options, you can use SST.
	if ( ! empty( $allcaps['manage_options'] ) ) {
		$allcaps['authenticate_sst'] = true;
	}

	return $allcaps;
}
