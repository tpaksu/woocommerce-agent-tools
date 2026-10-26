<?php
/**
 * The two questions a route is asked: does it change the store, and may an agent see it.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The two questions a route is asked: does it change the store, and may an agent see it.
 */
final class WC_Agent_Tools_Policy {

	/**
	 * Whether a route is read-only. Method is the default signal and a good one, but it
	 * is not reliable: `POST /wcshipping/v1/label/rate` changes nothing, while
	 * `POST /wcshipping/v1/label/purchase/{id}` spends money and writes no rows. An
	 * author declares what the method cannot say.
	 *
	 * @param string $route  Route pattern.
	 * @param string $method HTTP method.
	 */
	public static function is_readonly( string $route, string $method ): bool {
		$readonly = 'GET' === $method;
		if ( ! $readonly && isset( WC_Agent_Tools_Config::QUERY_BY_POST[ $route ] ) ) {
			$readonly = true;
		}

		/**
		 * Lets a plugin author declare a route read-only despite its HTTP method,
		 * or withhold one the method would otherwise expose.
		 *
		 * @since 0.1.0
		 *
		 * @param bool   $readonly Whether the route is read-only.
		 * @param string $route    Route pattern.
		 * @param string $method   HTTP method.
		 */
		return (bool) apply_filters( 'woocommerce_agent_tools_route_is_readonly', $readonly, $route, $method );
	}

	/**
	 * Whether an agent may see a route at all.
	 *
	 * Everything derivable is exposed. The boundary is the authenticated user's
	 * capabilities, enforced by each route's own permission callback, so an agent
	 * reaches exactly what that user reaches in wp-admin and nothing more. Hiding a
	 * route the user can already call buys no safety: it only costs the agent the
	 * knowledge that the route exists, and it will reach for the REST API instead.
	 *
	 * @param string              $route    Route pattern.
	 * @param string              $method   HTTP method.
	 * @param bool                $is_read Whether the route was judged read-only.
	 * @param array<string,mixed> $args    The route's declared arguments.
	 */
	public static function is_public( string $route, string $method, bool $is_read, array $args ): bool {
		$public = true;

		/**
		 * Lets a plugin author expose a write, or withhold a read.
		 *
		 * @since 0.1.0
		 *
		 * @param bool                $public   Whether the route is exposed to agents.
		 * @param string              $route    Route pattern.
		 * @param string              $method   HTTP method.
		 * @param bool                $is_read Whether the route is read-only.
		 * @param array<string,mixed> $args    The route's declared arguments.
		 */
		return (bool) apply_filters( 'woocommerce_agent_tools_route_is_public', $public, $route, $method, $is_read, $args );
	}
}
