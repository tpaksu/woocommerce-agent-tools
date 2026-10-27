<?php
/**
 * Recovers the parameter names a handler reads but its route never declared.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

namespace WooCommerce\AgentTools\Route;

use WooCommerce\AgentTools\Config;
use ReflectionMethod;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recovers the parameter names a handler reads but its route never declared.
 *
 * `register_rest_route()` args are the only machine-readable description of a payload, and a
 * controller is free to ignore them: `LabelPurchaseRESTController` declares two optional args
 * and then requires seven more from the body. An agent offered that schema either guesses or
 * goes reading the source, and guessing on a call that spends money is the worse of the two.
 *
 * The handler body names them, so read it. This yields names, never types, and never marks a
 * parameter required: a false positive here would reject a call that works today.
 */
final class Params {

	/**
	 * Handler file contents, keyed by path, for the life of one compile.
	 *
	 * @var array<string,string[]>
	 */
	private $files = array();

	/**
	 * Parameter names a handler reads, mapped to whether the handler treats them as required.
	 *
	 * @param callable|array<int,mixed>|string|null $callback Route callback.
	 * @return array<string,bool>
	 */
	public function discover( $callback ): array {
		$source = $this->handler_source( $callback );
		if ( '' === $source ) {
			return array();
		}

		$found = array();

		// Names the handler reads straight off the request, by index or by getter.
		if ( preg_match_all( '/\$request(?:\[|->get_param\(\s*)[\'"]([a-z0-9_]+)[\'"]/i', $source, $matches ) ) {
			foreach ( $matches[1] as $name ) {
				$found[ $name ] = false;
			}
		}

		// Names indexed out of the decoded JSON body.
		if ( preg_match_all( '/get_json_params\(\)\s*\[\s*[\'"]([a-z0-9_]+)[\'"]/i', $source, $matches ) ) {
			foreach ( $matches[1] as $name ) {
				$found[ $name ] = false;
			}
		}

		// A list of names handed to a checker alongside the request, where a leading "?"
		// marks the optional ones. WooCommerce Shipping writes its whole payload this way.
		if ( preg_match_all( '/_params\(\s*\$request,\s*array\(([^)]*)\)/s', $source, $matches ) ) {
			foreach ( $matches[1] as $list ) {
				if ( ! preg_match_all( '/[\'"](\??[a-z0-9_]+)[\'"]/i', $list, $names ) ) {
					continue;
				}
				foreach ( $names[1] as $name ) {
					$optional                     = 0 === strpos( $name, '?' );
					$found[ ltrim( $name, '?' ) ] = ! $optional;
				}
			}
		}

		// Compare case-insensitively, but keep the name exactly as the handler spells it: a
		// route that reads `isVerified` does not read `isverified`, and publishing the
		// lowercase form sent a caller to a parameter that does not exist.
		foreach ( array_keys( $found ) as $name ) {
			$lower = strtolower( (string) $name );
			if ( in_array( $lower, Config::BOILERPLATE_ARGS, true ) || 'id' === $lower ) {
				unset( $found[ $name ] );
			}
		}

		return $found;
	}

	/**
	 * Forget the per-compile file cache.
	 */
	public function reset(): void {
		$this->files = array();
	}

	/**
	 * The source of the method behind a route callback.
	 *
	 * @param callable|array<int,mixed>|string|null $callback Route callback.
	 */
	private function handler_source( $callback ): string {
		if ( ! is_array( $callback ) || 2 !== count( $callback ) || ! is_string( $callback[1] ) ) {
			return '';
		}

		try {
			$target = is_object( $callback[0] ) ? $callback[0] : (string) $callback[0];
			if ( is_string( $target ) && ! class_exists( $target ) ) {
				return '';
			}
			$method = new ReflectionMethod( $target, $callback[1] );
			$path   = (string) $method->getFileName();
			if ( '' === $path ) {
				return '';
			}
			if ( ! isset( $this->files[ $path ] ) ) {
				$lines                = file( $path );
				$this->files[ $path ] = is_array( $lines ) ? $lines : array();
			}
			$start = $method->getStartLine() - 1;
			$count = $method->getEndLine() - $method->getStartLine() + 1;
			return implode( '', array_slice( $this->files[ $path ], $start, $count ) );
		} catch ( Throwable $e ) {
			return '';
		}
	}
}
