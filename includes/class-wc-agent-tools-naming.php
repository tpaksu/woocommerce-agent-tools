<?php
/**
 * Turns a route and method into an ability name, label and description.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a route and method into an ability name, label and description.
 */
final class WC_Agent_Tools_Naming {

	/**
	 * Collapse an operation word into a class.
	 *
	 * @param string $word Word.
	 */
	public static function op_class( string $word ): string {
		$word = strtolower( $word );
		$map  = array(
			// Fetching one record by id is not the same capability as querying a collection.
			// Collapsing both into `read` let WooCommerce's curated `products-query` suppress
			// the derived `products-get`, leaving no way to read a single product in full.
			'get'      => 'fetch',
			'list'     => 'read',
			'query'    => 'read',
			'report'   => 'read',
			'search'   => 'read',
			'overview' => 'read',
			'read'     => 'read',
			'create'   => 'create',
			'add'      => 'create',
			'update'   => 'update',
			'edit'     => 'update',
			'set'      => 'update',
			'delete'   => 'delete',
			'remove'   => 'delete',
		);
		return $map[ $word ] ?? '';
	}

	/**
	 * Normalise a resource for matching: singularise crudely and sort words.
	 *
	 * @param string $resource_name Resource name.
	 */
	public static function resource_key( string $resource_name ): string {
		$split = preg_split( '/[-_\s]+/', strtolower( $resource_name ) );
		$words = array_filter( is_array( $split ) ? $split : array() );
		$words = array_map(
			static function ( $w ) {
				return rtrim( $w, 's' );
			},
			$words
		);
		sort( $words );
		return implode( '-', $words );
	}

	/**
	 * Keep only the newest version of each versioned namespace family.
	 * A merchant has one products screen, not three, so an agent gets one tool, not three.
	 *
	 * @param string[] $namespaces Registered namespaces.
	 * @return array<string,bool>
	 */
	public static function latest_namespaces( array $namespaces ): array {
		$best = array();
		$keep = array();
		foreach ( $namespaces as $ns ) {
			if ( preg_match( '#^(.*)/v(\\d+)$#', (string) $ns, $m ) ) {
				$base = $m[1];
				$ver  = (int) $m[2];
				if ( ! isset( $best[ $base ] ) || $ver > $best[ $base ] ) {
					$best[ $base ] = $ver;
				}
			} else {
				$keep[ (string) $ns ] = true;
			}
		}
		foreach ( $best as $base => $ver ) {
			$keep[ $base . '/v' . $ver ] = true;
		}
		return $keep;
	}

	/**
	 * Collapse a handler's methods into distinct operations, one representative method each.
	 * WP_REST_Server::EDITABLE registers POST, PUT and PATCH against one callback, which is
	 * one operation, not three.
	 *
	 * @param string   $route   Route.
	 * @param string[] $methods Methods on this handler.
	 * @return array<string,string> operation => chosen method
	 */
	public static function operations( string $route, array $methods ): array {
		$rank = array(
			'PUT'   => 3,
			'PATCH' => 2,
			'POST'  => 1,
		);
		$out  = array();
		foreach ( $methods as $method ) {
			if ( 'HEAD' === $method ) {
				continue;
			}
			$op = self::operation( $route, (string) $method );
			if ( ! isset( $out[ $op ] ) ) {
				$out[ $op ] = (string) $method;
				continue;
			}
			if ( ( $rank[ $method ] ?? 0 ) > ( $rank[ $out[ $op ] ] ?? 0 ) ) {
				$out[ $op ] = (string) $method;
			}
		}
		return $out;
	}

	/**
	 * Operation name for a route and method.
	 *
	 * @param string $route  Route.
	 * @param string $method Method.
	 */
	private static function operation( string $route, string $method ): string {
		$clean = rtrim( $route, '/' );
		$last  = (string) substr( $clean, (int) strrpos( $clean, '/' ) + 1 );
		$is_id = false !== strpos( $last, '(?P<' );

		// An action route: a literal tail after a path parameter.
		if ( ! $is_id && false !== strpos( $clean, '(?P<' ) && 'POST' === $method ) {
			return self::slugify( $last );
		}

		switch ( $method ) {
			case 'GET':
				return $is_id ? 'get' : 'list';
			case 'POST':
				return $is_id ? 'update' : 'create';
			case 'PUT':
			case 'PATCH':
				return 'update';
			case 'DELETE':
				return 'delete';
			default:
				return strtolower( $method );
		}
	}

	/**
	 * Resource words from a route path.
	 *
	 * @param string $route     Route.
	 * @param string $route_namespace Route namespace.
	 */
	public static function resource_of( string $route, string $route_namespace ): string {
		$path  = trim( (string) preg_replace( '#\(\?P<[^>]+>[^)]*\)#', '', $route ), '/' );
		$path  = (string) preg_replace( '#^' . preg_quote( $route_namespace, '#' ) . '#', '', $path );
		$parts = array_filter( explode( '/', trim( str_replace( '\\', '', $path ), '/' ) ) );
		return implode( '-', $parts );
	}

	/**
	 * A short label.
	 *
	 * @param string $method   Method.
	 * @param string $resource_name Resource name.
	 * @param string $op       Operation.
	 */
	public static function label( string $method, string $resource_name, string $op ): string {
		$verbs = array(
			'GET'    => 'Get',
			'POST'   => 'Create',
			'PUT'    => 'Update',
			'PATCH'  => 'Update',
			'DELETE' => 'Delete',
		);
		$verb  = $verbs[ $method ] ?? ucfirst( strtolower( $method ) );
		if ( 'list' === $op ) {
			$verb = 'List';
		}
		if ( ! in_array( $op, array( 'list', 'get', 'create', 'update', 'delete' ), true ) ) {
			$verb = ucfirst( str_replace( '-', ' ', $op ) );
			return trim( $verb . ' for ' . str_replace( '-', ' ', $resource_name ) );
		}
		return trim( $verb . ' ' . str_replace( '-', ' ', $resource_name ) );
	}

	/**
	 * Tier-one composed description: method verb, resource, schema title,
	 * a store-wide-unique docblock summary when there is one, and the parameter names.
	 *
	 * @param string              $method   Method.
	 * @param string              $resource_name Resource.
	 * @param string              $title    Schema title.
	 * @param string              $op       Operation.
	 * @param string              $summary  Docblock summary.
	 * @param array<string,mixed> $args     Schema args.
	 */
	public static function description( string $method, string $resource_name, string $title, string $op, string $summary, array $args ): string {
		$verbs = array(
			'GET'    => 'Retrieve',
			'POST'   => 'Create',
			'PUT'    => 'Update',
			'PATCH'  => 'Update',
			'DELETE' => 'Delete',
		);
		$verb  = $verbs[ $method ] ?? ucfirst( strtolower( $method ) );

		$object = str_replace( '-', ' ', $resource_name );
		if ( '' !== $title && $title !== $resource_name ) {
			$object .= ' (' . $title . ')';
		}

		if ( '' !== $summary && WC_Agent_Tools_Attribution::summary_is_unique( $summary ) ) {
			$out = rtrim( $summary, '.' ) . '.';
		} elseif ( ! in_array( $op, array( 'list', 'get', 'create', 'update', 'delete' ), true ) ) {
			$out = ucfirst( str_replace( '-', ' ', $op ) ) . ' for ' . trim( $object ) . '.';
		} else {
			$out = $verb . ' ' . trim( $object ) . '.';
		}

		$boring = WC_Agent_Tools_Config::BOILERPLATE_ARGS;
		$names  = array();
		foreach ( $args as $key => $spec ) {
			if ( in_array( $key, $boring, true ) ) {
				continue;
			}
			if ( ! empty( $spec['description'] ) ) {
				$names[] = $key;
			}
		}
		if ( $names ) {
			$out .= ' Parameters: ' . implode( ', ', array_slice( $names, 0, 10 ) ) . '.';
		}
		return $out;
	}

	/**
	 * Slug-safe string.
	 *
	 * @param string $value Value.
	 */
	public static function slugify( string $value ): string {
		$value = strtolower( (string) preg_replace( '/[^a-zA-Z0-9]+/', '-', $value ) );
		return trim( (string) preg_replace( '/-+/', '-', $value ), '-' );
	}   /**
		 * The named placeholders a route pattern fills from the path, so a body schema does not
		 * also advertise them.
		 *
		 * @param string $route Route pattern.
		 * @return string[]
		 */
	public static function url_param_specs( string $route ): array {
		if ( ! preg_match_all( '#\(\?P<([^>]+)>([^)]*)\)#', $route, $matches, PREG_SET_ORDER ) ) {
			return array();
		}
		$specs = array();
		foreach ( $matches as $match ) {
			$specs[ strtolower( $match[1] ) ] = $match[2];
		}
		return $specs;
	}

	/**
	 * The named placeholders a route pattern fills from the path, so a body schema does not
	 * also advertise them.
	 *
	 * @param string $route Route pattern.
	 * @return string[]
	 */
	public static function url_params( string $route ): array {
		if ( ! preg_match_all( '/\(\?P<([a-z0-9_]+)>/i', $route, $matches ) ) {
			return array();
		}
		return array_map( 'strtolower', $matches[1] );
	}
}
