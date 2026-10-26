<?php
/**
 * Removes everything this plugin stored. The compiled surface is a cache and the reference
 * map holds no secrets, so there is nothing here worth keeping once the plugin is gone.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_transient( 'wcat_descriptors' );

/**
 * The reference map is per user and the search results are per term, so both are families
 * of transients rather than single keys.
 */
global $wpdb;
$wcat_transients = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_wcat_search_' ) . '%',
		$wpdb->esc_like( '_transient_wcat_secret_map_' ) . '%'
	)
);
foreach ( (array) $wcat_transients as $wcat_option_name ) {
	delete_transient( substr( (string) $wcat_option_name, strlen( '_transient_' ) ) );
}
