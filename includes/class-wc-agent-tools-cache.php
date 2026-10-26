<?php
/**
 * The compiled surface, cached against a fingerprint of the active plugins and their versions.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The compiled surface, cached against a fingerprint of the active plugins and their versions.
 */
final class WC_Agent_Tools_Cache {

	/**
	 * Cache fingerprint. Catches activate/deactivate for free from an autoloaded option,
	 * and plugin updates from the version headers.
	 */
	public static function fingerprint(): string {
		$active = (array) get_option( 'active_plugins', array() );
		sort( $active );
		$parts = array();
		foreach ( $active as $file ) {
			$path    = WP_PLUGIN_DIR . '/' . $file;
			$version = '';
			if ( is_readable( $path ) ) {
				$data    = get_file_data( $path, array( 'v' => 'Version' ) );
				$version = (string) $data['v'];
			}
			$parts[] = $file . '@' . $version;
		}
		return md5( implode( '|', $parts ) );
	}

	/**
	 * Descriptors, from cache when the fingerprint still matches.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function descriptors(): array {
		$fp     = self::fingerprint();
		$cached = get_transient( WC_Agent_Tools_Config::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['fp'], $cached['d'] ) && $cached['fp'] === $fp ) {
			WC_Agent_Tools_Compiler::set_stats( isset( $cached['s'] ) ? (array) $cached['s'] : array(), true );
			return (array) $cached['d'];
		}

		$descriptors = WC_Agent_Tools_Compiler::compile();
		set_transient(
			WC_Agent_Tools_Config::CACHE_KEY,
			array(
				'fp' => $fp,
				'd'  => $descriptors,
				's'  => WC_Agent_Tools_Compiler::stats(),
			),
			DAY_IN_SECONDS
		);
		WC_Agent_Tools_Compiler::set_stats( WC_Agent_Tools_Compiler::stats(), false );
		return $descriptors;
	}

	/**
	 * Drop the cache (the manual refresh).
	 */
	public static function flush(): void {
		delete_transient( WC_Agent_Tools_Config::CACHE_KEY );
	}
}
