<?php
/**
 * Works out which plugin owns a route handler, whether its permission callback enforces anything, and what its docblock says.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Works out which plugin owns a route handler, whether its permission callback enforces anything, and what its docblock says.
 */
final class WC_Agent_Tools_Attribution {

	/**
	 * Memoised plugin-slug => is-a-woo-plugin.
	 *
	 * @var array<string,bool>
	 */
	private static $woo_plugin = array();

	/**
	 * Store-wide count of docblock summaries, for the uniqueness test.
	 *
	 * @var array<string,int>
	 */
	private static $summary_counts = array();

	/**
	 * The plugin a route handler belongs to, via its callback or failing that its permission
	 * callback.
	 *
	 * WooCommerce wraps cached read handlers in a closure, which erases the defining class.
	 * That left every such read unattributable while the writes on the same route resolved
	 * normally, so the derived surface kept `taxes-delete` and dropped `taxes-list`: an agent
	 * could destroy a tax rate it was never able to read. The permission callback on the same
	 * handler is not wrapped and names the same controller.
	 *
	 * @param array<string,mixed> $handler One route handler.
	 * @return string|null
	 */
	public static function owner_of_handler( array $handler ): ?string {
		$owner = self::owner_of( $handler['callback'] ?? null );
		return null !== $owner ? $owner : self::owner_of( $handler['permission_callback'] ?? null );
	}

	/**
	 * Map a callback to the plugin slug that defined it, via the CONCRETE class.
	 *
	 * @param mixed $cb Callback.
	 * @return string|null
	 */
	public static function owner_of( $cb ): ?string {
		try {
			if ( is_array( $cb ) && 2 === count( $cb ) ) {
				if ( is_object( $cb[0] ) ) {
					$ref = new ReflectionClass( $cb[0] );
				} elseif ( is_string( $cb[0] ) && class_exists( $cb[0] ) ) {
					$ref = new ReflectionClass( $cb[0] );
				} else {
					return null;
				}
			} elseif ( is_string( $cb ) && false !== strpos( $cb, '::' ) ) {
				$target = explode( '::', $cb )[0];
				if ( ! class_exists( $target ) ) {
					return null;
				}
				$ref = new ReflectionClass( $target );
			} elseif ( $cb instanceof Closure || ( is_string( $cb ) && function_exists( $cb ) ) ) {
				// A closure handler erases the defining class (see RestApiCache). Unattributable.
				return null;
			} elseif ( is_object( $cb ) && method_exists( $cb, '__invoke' ) ) {
				$ref = new ReflectionClass( $cb );
			} else {
				return null;
			}
		} catch ( Throwable $e ) {
			return null;
		}

		$file = (string) $ref->getFileName();
		if ( preg_match( '#/plugins/([^/]+)/#', $file, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Plugin header test for "is this a WooCommerce extension".
	 *
	 * @param string $slug Plugin directory slug.
	 */
	public static function is_woo_plugin( string $slug ): bool {
		if ( isset( self::$woo_plugin[ $slug ] ) ) {
			return self::$woo_plugin[ $slug ];
		}
		$is = false;
		if ( 'woocommerce' === $slug ) {
			$is = true;
		} else {
			foreach ( (array) glob( WP_PLUGIN_DIR . '/' . $slug . '/*.php' ) as $file ) {
				$data = get_file_data(
					$file,
					array(
						'name'   => 'Plugin Name',
						'rp'     => 'Requires Plugins',
						'wcmin'  => 'WC requires at least',
						'wctest' => 'WC tested up to',
						'woo'    => 'Woo',
					)
				);
				if ( '' === trim( (string) $data['name'] ) ) {
					continue;
				}
				if ( false !== stripos( (string) $data['rp'], 'woocommerce' )
					|| '' !== trim( (string) $data['wcmin'] )
					|| '' !== trim( (string) $data['wctest'] )
					|| '' !== trim( (string) $data['woo'] ) ) {
					$is = true;
				}
				break;
			}
		}
		self::$woo_plugin[ $slug ] = $is;
		return $is;
	}

	/**
	 * A permission callback that actually enforces something.
	 *
	 * @param mixed $cb Callback.
	 */
	public static function enforcing( $cb ): bool {
		if ( empty( $cb ) || ! is_callable( $cb ) ) {
			return false;
		}
		if ( is_string( $cb ) && in_array( strtolower( $cb ), array( '__return_true', '__return_empty_array' ), true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * First docblock line of a callback.
	 *
	 * @param mixed $cb Callback.
	 */
	public static function summary_of( $cb ): string {
		$doc = false;
		try {
			if ( is_array( $cb ) && 2 === count( $cb ) ) {
				$doc = ( new ReflectionMethod( is_object( $cb[0] ) ? get_class( $cb[0] ) : $cb[0], $cb[1] ) )->getDocComment();
			} elseif ( is_string( $cb ) || $cb instanceof Closure ) {
				$doc = ( new ReflectionFunction( $cb ) )->getDocComment();
			}
		} catch ( Throwable $e ) {
			return '';
		}
		if ( ! $doc ) {
			return '';
		}
		// Take the sentence, not the first physical line: a wrapped summary stopped at the
		// line break and shipped as "delegating each notification to the".
		$lines   = preg_split( '/\R/', $doc );
		$summary = '';
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = trim( (string) preg_replace( '#^\s*(/\*\*|\*/|\*)\s?#', '', $line ) );
			if ( '' === $line ) {
				if ( '' !== $summary ) {
					break;
				}
				continue;
			}
			if ( '@' === $line[0] ) {
				break;
			}
			$summary = '' === $summary ? $line : $summary . ' ' . $line;
			if ( preg_match( '/[.!?]$/', $line ) ) {
				break;
			}
		}
		return $summary;
	}

	/**
	 * Record how often each docblock summary occurs store-wide, so a description can tell
	 * a distinctive summary from one repeated across a dozen controllers.
	 *
	 * @param array<string,mixed> $routes Route table.
	 */
	public static function prime_summaries( array $routes ): void {
		self::$summary_counts = self::count_summaries( $routes );
	}

	/**
	 * Whether a docblock summary occurs exactly once store-wide, which is what makes it
	 * usable as a description rather than a label repeated across a dozen tools.
	 *
	 * @param string $summary The summary.
	 */
	public static function summary_is_unique( string $summary ): bool {
		return 1 === ( self::$summary_counts[ $summary ] ?? 0 );
	}

	/**
	 * Count how often each docblock summary occurs across the route table.
	 *
	 * @param array<string,mixed> $routes The REST route table.
	 * @return array<string,int>
	 */
	private static function count_summaries( array $routes ): array {
		$counts = array();
		foreach ( $routes as $handlers ) {
			foreach ( (array) $handlers as $handler ) {
				if ( ! is_array( $handler ) || ! isset( $handler['callback'] ) ) {
					continue;
				}
				$s = self::summary_of( $handler['callback'] );
				if ( '' !== $s ) {
					$counts[ $s ] = ( $counts[ $s ] ?? 0 ) + 1;
				}
			}
		}
		return $counts;
	}
}
