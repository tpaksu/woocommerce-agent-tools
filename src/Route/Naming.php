<?php
/**
 * Turns a route and method into an ability name, label and description.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

namespace WooCommerce\AgentTools\Route;

use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a route and method into an ability name, label and description.
 */
final class Naming {

	/**
	 * Route ownership lookup.
	 *
	 * @var Attribution
	 */
	private $attribution;

	/**
	 * Wire the collaborators this class calls.
	 *
	 * @param Attribution $attribution Route ownership lookup.
	 */
	public function __construct( Attribution $attribution ) {
		$this->attribution = $attribution;
	}

	/**
	 * Collapse an operation word into a class.
	 *
	 * @param string $word Word.
	 */
	public function op_class( string $word ): string {
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
	public function resource_key( string $resource_name ): string {
		$split = preg_split( '/[-_\s]+/', strtolower( $resource_name ) );
		$words = array_filter( is_array( $split ) ? $split : array() );
		$words = array_map(
			function ( $w ) {
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
	public function latest_namespaces( array $namespaces ): array {
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
	 * @param string   $route         Route.
	 * @param string[] $methods       Methods on this handler.
	 * @param bool     $route_has_get Whether any handler on this route serves GET.
	 * @return array<string,string> operation => chosen method
	 */
	public function operations( string $route, array $methods, bool $route_has_get = false ): array {
		$rank = array(
			'PUT'   => 3,
			'PATCH' => 2,
			'POST'  => 1,
		);
		$edit = '';
		$out  = array();
		foreach ( $methods as $method ) {
			if ( 'HEAD' === $method ) {
				continue;
			}
			// One callback serves every method listed here, so the edit verbs are one operation.
			// GET and DELETE are not: a different capability, and a different safety class.
			if ( isset( $rank[ $method ] ) ) {
				if ( '' === $edit || $rank[ $method ] > $rank[ $edit ] ) {
					$edit = (string) $method;
				}
				continue;
			}
			$out[ $this->operation( $route, (string) $method, $route_has_get ) ] = (string) $method;
		}

		if ( '' !== $edit ) {
			// POST represents the merge whenever the handler serves it, so the surviving name
			// is the one that says what the route does: `create` on a collection, the tail on
			// an action route, `update` on an id. Ranking by verb instead kept `update` and
			// lost all three, and dispatched a POST route as PUT.
			if ( in_array( 'POST', $methods, true ) ) {
				$edit = 'POST';
			}
			$op = $this->operation( $route, $edit, $route_has_get );
			// Only GET and DELETE can be in `$out` here, and neither shares an op with an edit
			// verb on any route in WooCommerce. Refuse to overwrite one rather than assume it.
			if ( ! isset( $out[ $op ] ) ) {
				$out[ $op ] = $edit;
			}
		}
		return $out;
	}

	/**
	 * Whether this is an action route: a literal tail after a path parameter, such as
	 * `/reports/(?P<type>[a-z]+)/export`, which is named after the tail rather than the verb.
	 *
	 * A GET on the same route means the tail is a nested collection instead, so POST creates a
	 * child and the verb is the name. Without that test `/orders/(?P<id>\d+)/refunds` compiled
	 * to `orders-refunds-refunds` rather than `orders-refunds-create`.
	 *
	 * @param string $route         Route.
	 * @param bool   $route_has_get Whether any handler on this route serves GET.
	 */
	private function is_action_route( string $route, bool $route_has_get ): bool {
		if ( $route_has_get ) {
			return false;
		}
		$clean = rtrim( $route, '/' );
		$last  = (string) substr( $clean, (int) strrpos( $clean, '/' ) + 1 );

		return false === strpos( $last, '(?P<' ) && false !== strpos( $clean, '(?P<' );
	}

	/**
	 * Operation name for a route and method.
	 *
	 * @param string $route         Route.
	 * @param string $method        Method.
	 * @param bool   $route_has_get Whether any handler on this route serves GET.
	 */
	private function operation( string $route, string $method, bool $route_has_get ): string {
		$clean = rtrim( $route, '/' );
		$last  = (string) substr( $clean, (int) strrpos( $clean, '/' ) + 1 );
		$is_id = false !== strpos( $last, '(?P<' );

		if ( 'POST' === $method && $this->is_action_route( $route, $route_has_get ) ) {
			return $this->slugify( $last );
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
	public function resource_of( string $route, string $route_namespace ): string {
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
	public function label( string $method, string $resource_name, string $op ): string {
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
	 * Tier-one composed description: method verb, resource, schema title, and a
	 * store-wide-unique docblock summary when there is one.
	 *
	 * Deliberately omits the parameter names. They duplicate `input_schema`, which
	 * get-ability-info returns, and cost 18,747 bytes across 306 descriptions.
	 *
	 * @param string $method        Method.
	 * @param string $resource_name Resource.
	 * @param string $title         Schema title.
	 * @param string $op            Operation.
	 * @param string $summary       Docblock summary.
	 */
	public function description( string $method, string $resource_name, string $title, string $op, string $summary ): string {
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

		if ( '' !== $summary && $this->attribution->summary_is_unique( $summary ) ) {
			$out = rtrim( $summary, '.' ) . '.';
		} elseif ( ! in_array( $op, array( 'list', 'get', 'create', 'update', 'delete' ), true ) ) {
			$out = ucfirst( str_replace( '-', ' ', $op ) ) . ' for ' . trim( $object ) . '.';
		} else {
			$out = $verb . ' ' . trim( $object ) . '.';
		}

		return $out;
	}

	/**
	 * Slug-safe string.
	 *
	 * @param string $value Value.
	 */
	public function slugify( string $value ): string {
		$value = strtolower( (string) preg_replace( '/[^a-zA-Z0-9]+/', '-', $value ) );
		return trim( (string) preg_replace( '/-+/', '-', $value ), '-' );
	}

	/**
	 * The named placeholders a route pattern fills from the path, so a body schema does not
	 * also advertise them.
	 *
	 * @param string $route Route pattern.
	 * @return string[]
	 */
	public function url_param_specs( string $route ): array {
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
	public function url_params( string $route ): array {
		if ( ! preg_match_all( '/\(\?P<([a-z0-9_]+)>/i', $route, $matches ) ) {
			return array();
		}
		return array_map( 'strtolower', $matches[1] );
	}
}
