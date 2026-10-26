<?php
/**
 * Applies the confirmation gate to REST calls that skip the tools entirely.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies the confirmation gate to REST calls that skip the tools entirely.
 *
 * The gate on an ability only guards the ability. The application password that reaches the
 * MCP endpoint reaches every other REST route on the site just as well, so an agent that knows
 * a route can post straight to it and nothing in the tool layer is consulted. Verified: the
 * same credential activated a plugin through `POST /wp-json/wc-admin/plugins/activate` with no
 * token and no preview.
 *
 * That path is the one that survives on a hosted store, where there is no source to read and
 * no shell, but the REST API is still a single HTTP call away.
 *
 * Scope matters here. Gating every application-password write would break the integrations
 * that legitimately use them — inventory sync, order feeds, mobile apps — so this applies only
 * to credentials issued from this plugin's own screen, whose uuids it records. Every other
 * password behaves exactly as before.
 */
final class WC_Agent_Tools_Rest_Guard {

	const OPTION = 'wcat_agent_password_uuids';

	/**
	 * Hook up.
	 */
	public static function init(): void {
		// Last, deliberately. Gift Cards hooks this filter with `accepted_args` of 0 and
		// Product Bundles with 1, so both discard whatever a previous callback returned.
		// A guard that runs before them is silently thrown away.
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard' ), PHP_INT_MAX, 3 );
	}

	/**
	 * Record a password this plugin issued, so its calls can be held to the gate.
	 *
	 * @param string $uuid Application password uuid.
	 */
	public static function remember_password( string $uuid ): void {
		$uuids = (array) get_option( self::OPTION, array() );
		if ( ! in_array( $uuid, $uuids, true ) ) {
			$uuids[] = $uuid;
			update_option( self::OPTION, array_slice( $uuids, -50 ), false );
		}
	}

	/**
	 * Hold a write from an agent credential until it has been previewed.
	 *
	 * @param mixed $result  Pre-dispatch result, null to continue.
	 * @param mixed $server  Server instance.
	 * @param mixed $request The request, as whatever reached this filter.
	 * @return mixed
	 */
	public static function guard( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		$method = strtoupper( (string) $request->get_method() );
		if ( in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return $result;
		}

		if ( ! self::is_agent_credential() ) {
			return $result;
		}

		// An ability's own dispatch is gated one layer up; gating it here is the deadlock.
		if ( WC_Agent_Tools_Dispatcher::is_internal() ) {
			return $result;
		}

		$route = (string) $request->get_route();

		// The MCP endpoint is itself a POST. Its abilities carry the gate already, and
		// stopping it here would make the tools unreachable.
		if ( 0 === strpos( ltrim( $route, '/' ), 'mcp/' ) ) {
			return $result;
		}

		// A query sent by POST because its payload will not fit in a URL is still a read.
		foreach ( array_keys( WC_Agent_Tools_Config::QUERY_BY_POST ) as $pattern ) {
			if ( preg_match( '#^' . str_replace( '#', '\\#', (string) $pattern ) . '$#', $route ) ) {
				return $result;
			}
		}

		/**
		 * Filters whether a direct REST write from an agent credential is held for
		 * confirmation.
		 *
		 * @since 0.4.0
		 *
		 * @param bool            $guarded Whether to hold the request.
		 * @param string          $route   The route being requested.
		 * @param string          $method  HTTP method.
		 * @param WP_REST_Request $request The request.
		 */
		if ( ! apply_filters( 'woocommerce_agent_tools_guard_rest_request', true, $route, $method, $request ) ) {
			return $result;
		}

		$params = $request->get_params();
		unset( $params[ WC_Agent_Tools_Confirm::FIELD ] );

		$allowed = WC_Agent_Tools_Confirm::gate(
			$method . ' ' . $route,
			$method . ' ' . preg_replace( '#\(\?P<([^>]+)>[^)]*\)#', '{$1}', $route ),
			array_merge( $params, array( WC_Agent_Tools_Confirm::FIELD => (string) $request->get_param( WC_Agent_Tools_Confirm::FIELD ) ) )
		);

		if ( is_wp_error( $allowed ) ) {
			$allowed->add_data(
				array_merge(
					(array) $allowed->get_error_data(),
					array( 'reached_without_a_tool' => 'This route was called directly rather than through a tool. The tools describe their parameters and report what they would do; prefer them.' )
				)
			);
			return $allowed;
		}

		return $result;
	}

	/**
	 * Whether this request is agent traffic, and so subject to the gate.
	 *
	 * The gate exists to make an agent state a write before it happens. It is not a site-wide
	 * write lock: WordPress ships `/wp-abilities/v1/abilities/{name}/run`, and another plugin
	 * may execute an ability during an ordinary admin request. Holding those would break
	 * callers that never asked for a confirmation protocol and have nowhere to show a preview.
	 * Same reasoning as the credential scoping below.
	 */
	public static function is_agent_context(): bool {
		if ( self::is_agent_credential() ) {
			return true;
		}

		// A client that reaches the MCP endpoint some other way is still an agent.
		$route = isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] )
			? (string) ( $GLOBALS['wp']->query_vars['rest_route'] ?? '' )
			: '';

		return str_starts_with( ltrim( $route, '/' ), 'mcp/' );
	}

	/**
	 * Whether this request authenticated with a password this plugin issued.
	 */
	private static function is_agent_credential(): bool {
		$uuid = $GLOBALS['wp_rest_application_password_uuid'] ?? null;
		if ( ! is_string( $uuid ) || '' === $uuid ) {
			return false;
		}
		return in_array( $uuid, (array) get_option( self::OPTION, array() ), true );
	}
}
