<?php
/**
 * Walks the REST route table and emits one descriptor per callable operation.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Walks the REST route table and emits one descriptor per callable operation.
 */
final class WC_Agent_Tools_Compiler {

	/**
	 * Stats from the last compile, for the demo harness.
	 *
	 * @var array<string,mixed>
	 */
	private static $stats = array();

	/**
	 * What the last compile counted, or what the cache recorded for it.
	 *
	 * @return array<string,mixed>
	 */
	public static function stats(): array {
		return self::$stats;
	}

	/**
	 * Replace the recorded stats, and say whether they came from the cache rather than
	 * from a compile on this request.
	 *
	 * @param array<string,mixed> $stats      The stats.
	 * @param bool                $from_cache Whether they were restored from the cache.
	 */
	public static function set_stats( array $stats, bool $from_cache ): void {
		$stats['from_cache'] = $from_cache;
		self::$stats         = $stats;
	}

	/**
	 * Walk the route table and build descriptors. Pure data: no closures, so it caches.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function compile(): array {
		$server     = rest_get_server();
		$routes     = $server->get_routes();
		$namespaces = $server->get_namespaces();
		$stats      = array(
			'routes'          => count( $routes ),
			'candidates'      => 0,
			'skip_index'      => 0,
			'skip_route_deny' => 0,
			'skip_ns_deny'    => 0,
			'skip_batch'      => 0,
			'skip_perm'       => 0,
			'skip_owner'      => 0,
			'skip_not_woo'    => 0,
			'skip_dedupe'     => 0,
			'skip_name_taken' => 0,
			'emitted'         => 0,
		);

		$keep_ns              = WC_Agent_Tools_Naming::latest_namespaces( $namespaces );
		$stats['skip_old_ns'] = 0;
		WC_Agent_Tools_Attribution::prime_summaries( $routes );
		$declared = WC_Agent_Tools_Dedupe::declared_signatures();
		$seen     = array();
		$claimed  = array();
		$out      = array();

		$routes = self::by_namespace_preference( $server, $routes );

		foreach ( $routes as $route => $handlers ) {
			$trimmed = trim( (string) $route, '/' );
			if ( in_array( $trimmed, $namespaces, true ) || '' === $trimmed ) {
				++$stats['skip_index'];
				continue;
			}
			if ( self::prefixed( (string) $route, WC_Agent_Tools_Config::ROUTE_DENY ) ) {
				++$stats['skip_route_deny'];
				continue;
			}
			if ( str_ends_with( rtrim( (string) $route, '/' ), '/batch' ) ) {
				++$stats['skip_batch'];
				continue;
			}

			$options         = $server->get_route_options( (string) $route );
			$route_namespace = isset( $options['namespace'] ) ? (string) $options['namespace'] : '';
			if ( in_array( explode( '/', $route_namespace )[0], WC_Agent_Tools_Config::NS_DENY, true ) ) {
				++$stats['skip_ns_deny'];
				continue;
			}
			if ( '' !== $route_namespace && ! isset( $keep_ns[ $route_namespace ] ) ) {
				++$stats['skip_old_ns'];
				continue;
			}
			$title = WC_Agent_Tools_Schema::schema_title( $options );

			foreach ( $handlers as $handler ) {
				if ( ! is_array( $handler ) || ! isset( $handler['methods'], $handler['callback'] ) ) {
					continue;
				}

				if ( ! WC_Agent_Tools_Attribution::enforcing( $handler['permission_callback'] ?? null ) ) {
					$stats['skip_perm'] += count( array_filter( $handler['methods'] ) );
					continue;
				}

				$owner = WC_Agent_Tools_Attribution::owner_of_handler( $handler );
				if ( null === $owner ) {
					$stats['skip_owner'] += count( array_filter( $handler['methods'] ) );
					continue;
				}
				if ( ! WC_Agent_Tools_Attribution::is_woo_plugin( $owner ) ) {
					$stats['skip_not_woo'] += count( array_filter( $handler['methods'] ) );
					continue;
				}

				$summary       = WC_Agent_Tools_Attribution::summary_of( $handler['callback'] );
				$declared_args = isset( $handler['args'] ) ? (array) $handler['args'] : array();

				/**
				 * Filters the REST arg definitions a route is treated as having declared.
				 *
				 * A controller is free to read parameters it never declared, and nothing here
				 * can invent their types. This is how the plugin that owns a route states them
				 * without writing an ability definition: return the args it should have
				 * registered, in `register_rest_route()` form.
				 *
				 * @since 0.3.0
				 *
				 * @param array<string,mixed> $declared_args The route's declared args.
				 * @param string              $route         Route pattern.
				 * @param array<string,bool>  $methods       Methods this handler serves.
				 */
				$declared_args = (array) apply_filters( 'woocommerce_agent_tools_route_args', $declared_args, (string) $route, (array) $handler['methods'] );

				$model      = WC_Agent_Tools_Schema::from_args( $declared_args );
				$url_params = WC_Agent_Tools_Naming::url_params( (string) $route );
				$found      = WC_Agent_Tools_Schema::with_discovered(
					$model['properties'],
					WC_Agent_Tools_Params::discover( $handler['callback'] ),
					$url_params
				);

				$with_path  = WC_Agent_Tools_Schema::with_path_params(
					$found['properties'],
					$model['required'],
					WC_Agent_Tools_Naming::url_param_specs( (string) $route )
				);
				$args       = $with_path['properties'];
				$required   = $with_path['required'];
				$undeclared = $found['undeclared'];

				foreach ( WC_Agent_Tools_Naming::operations( (string) $route, array_keys( array_filter( $handler['methods'] ) ) ) as $op => $method ) {
					$key = $route . ' ' . $op;
					if ( isset( $seen[ $key ] ) ) {
						continue;
					}
					$seen[ $key ] = true;
					++$stats['candidates'];

					$resource_name = WC_Agent_Tools_Naming::resource_of( (string) $route, $route_namespace );
					$name          = $owner . '/' . WC_Agent_Tools_Naming::slugify( ( '' !== $resource_name ? $resource_name : $title ) . '-' . $op );
					$readonly      = WC_Agent_Tools_Policy::is_readonly( (string) $route, (string) $method );

					$sig = $owner . '|' . WC_Agent_Tools_Naming::resource_key( $resource_name ) . '|' . WC_Agent_Tools_Naming::op_class( $op );
					// Yield to a hand-written ability only when it publishes at least as much as
					// this one would. A curated tool can be a deliberate subset, and losing the
					// richer derived version costs the agent filters it has no other way to reach.
					if ( isset( $declared[ $sig ] ) && count( $args ) <= (int) $declared[ $sig ]['params'] ) {
						++$stats['skip_dedupe'];
						continue;
					}

					// Two namespaces can reduce to one name; see Config::NS_PREFER for which wins.
					if ( isset( $claimed[ $name ] ) ) {
						++$stats['skip_name_taken'];
						continue;
					}
					$claimed[ $name ] = true;

					$out[] = array(
						'name'        => $name,
						'label'       => WC_Agent_Tools_Naming::label( $method, $resource_name, $op ),
						'description' => WC_Agent_Tools_Naming::description( $method, $resource_name, $title, $op, $summary, $args ),
						'route'       => (string) $route,
						'method'      => (string) $method,
						'owner'       => $owner,
						'readonly'    => $readonly,
						'public'      => WC_Agent_Tools_Policy::is_public( (string) $route, (string) $method, $readonly, $args ),
						'args'        => $args,
						'required'    => $required,
						'undeclared'  => $undeclared,
					);
					++$stats['emitted'];
				}
			}
		}

		$stats['declared_seen'] = count( $declared );
		self::$stats            = $stats;
		return $out;
	}

	/**
	 * Order routes so a preferred namespace claims a tool name before its mirror does.
	 *
	 * @param WP_REST_Server                 $server Server.
	 * @param array<string,array<int,mixed>> $routes Route table.
	 * @return array<string,array<int,mixed>>
	 */
	private static function by_namespace_preference( WP_REST_Server $server, array $routes ): array {
		$last    = count( WC_Agent_Tools_Config::NS_PREFER );
		$buckets = array();

		foreach ( $routes as $route => $handlers ) {
			$options   = $server->get_route_options( (string) $route );
			$namespace = isset( $options['namespace'] ) ? (string) $options['namespace'] : '';
			$rank      = array_search( $namespace, WC_Agent_Tools_Config::NS_PREFER, true );
			$bucket    = false === $rank ? $last : (int) $rank;

			$buckets[ $bucket ][ (string) $route ] = $handlers;
		}

		ksort( $buckets );

		$out = array();
		foreach ( $buckets as $bucket ) {
			foreach ( $bucket as $route => $handlers ) {
				$out[ $route ] = $handlers;
			}
		}
		return $out;
	}

	/**
	 * Whether a string starts with any of a list of prefixes.
	 *
	 * @param string   $value    Value.
	 * @param string[] $prefixes Prefixes.
	 */
	private static function prefixed( string $value, array $prefixes ): bool {
		foreach ( $prefixes as $prefix ) {
			if ( str_starts_with( $value, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
