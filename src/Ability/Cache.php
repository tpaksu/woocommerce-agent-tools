<?php
/**
 * The compiled surface, cached against a fingerprint of the active plugins and their versions.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

namespace WooCommerce\AgentTools\Ability;

use WooCommerce\AgentTools\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The compiled surface, cached against a fingerprint of the active plugins and their versions.
 */
final class Cache {

	/**
	 * The route table walker.
	 *
	 * @var Compiler
	 */
	private $compiler;

	/**
	 * Wire the collaborators this class calls.
	 *
	 * @param Compiler $compiler The route table walker.
	 */
	public function __construct( Compiler $compiler ) {
		$this->compiler = $compiler;
	}

	/**
	 * Cache fingerprint. Catches activate/deactivate for free from an autoloaded option,
	 * and plugin updates from the version headers.
	 */
	public function fingerprint(): string {
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
	public function descriptors(): array {
		$fp     = $this->fingerprint();
		$cached = get_transient( Config::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['fp'], $cached['d'] ) && $cached['fp'] === $fp ) {
			$this->compiler->set_stats( isset( $cached['s'] ) ? (array) $cached['s'] : array(), true );
			return (array) $cached['d'];
		}

		$descriptors = $this->compiler->compile();
		set_transient(
			Config::CACHE_KEY,
			array(
				'fp' => $fp,
				'd'  => $descriptors,
				's'  => $this->compiler->stats(),
			),
			DAY_IN_SECONDS
		);
		$this->compiler->set_stats( $this->compiler->stats(), false );
		return $descriptors;
	}

	/**
	 * Drop the cache (the manual refresh).
	 */
	public function flush(): void {
		delete_transient( Config::CACHE_KEY );
	}
}
