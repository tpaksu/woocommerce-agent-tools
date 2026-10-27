<?php
/**
 * Applies the confirmation gate to REST calls that skip the tools entirely.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

namespace WooCommerce\AgentTools\Safety;

use WooCommerce\AgentTools\Config;
use WooCommerce\AgentTools\Dispatcher;
use WP_REST_Request;

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
final class RestGuard {

	/**
	 * The confirmation protocol.
	 *
	 * @var Confirm
	 */
	private $confirm;

	/**
	 * Route dispatch.
	 *
	 * @var Dispatcher
	 */
	private $dispatcher;

	/**
	 * Wire the collaborators this class calls.
	 *
	 * @param Confirm    $confirm    The confirmation protocol.
	 * @param Dispatcher $dispatcher Route dispatch.
	 */
	public function __construct(
		Confirm $confirm,
		Dispatcher $dispatcher
	) {
		$this->confirm    = $confirm;
		$this->dispatcher = $dispatcher;
	}

	const OPTION = 'wcat_agent_password_uuids';

	/**
	 * Hook up.
	 */
	public function init(): void {
		// Last, deliberately. Gift Cards hooks this filter with `accepted_args` of 0 and
		// Product Bundles with 1, so both discard whatever a previous callback returned.
		// A guard that runs before them is silently thrown away.
		add_filter( 'rest_pre_dispatch', array( $this, 'guard' ), PHP_INT_MAX, 3 );
		// The claim above happens before the route validates parameters or checks permissions,
		// so it is not proof the call ran. `rest_dispatch_request` fires once both have passed;
		// anything still recorded after the callbacks gets its confirmation back.
		add_filter( 'rest_dispatch_request', array( $this, 'commit' ), PHP_INT_MAX, 2 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'release' ), PHP_INT_MAX, 3 );
		// Both, because neither covers everything: `after_callbacks` runs inside `dispatch()`,
		// so it also covers an internally dispatched request, while `post_dispatch` is the only
		// one that fires when no route matched, or when a `rest_pre_dispatch` callback
		// registered after ours hijacked the request. A `rest_dispatch_request` callback that
		// halts does reach `after_callbacks`: core only swaps the response and falls through.
		add_filter( 'rest_post_dispatch', array( $this, 'release' ), PHP_INT_MAX, 3 );
	}

	/**
	 * Record a password this plugin issued, so its calls can be held to the gate.
	 *
	 * @param string $uuid Application password uuid.
	 */
	public function remember_password( string $uuid ): void {
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
	public function guard( $result, $server, $request ) {
		if ( null !== $result || ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		$method = strtoupper( (string) $request->get_method() );
		if ( in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return $result;
		}

		if ( ! $this->is_agent_credential() ) {
			return $result;
		}

		// An ability's own dispatch is gated one layer up; gating it here is the deadlock.
		if ( $this->dispatcher->is_internal() ) {
			return $result;
		}

		$route = (string) $request->get_route();

		// The MCP endpoint is itself a POST. Its abilities carry the gate already, and
		// stopping it here would make the tools unreachable.
		if ( 0 === strpos( ltrim( $route, '/' ), 'mcp/' ) ) {
			return $result;
		}

		// A query sent by POST because its payload will not fit in a URL is still a read.
		foreach ( array_keys( Config::QUERY_BY_POST ) as $pattern ) {
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
		unset( $params[ Confirm::FIELD ] );

		$allowed = $this->confirm->gate(
			$this->key( $method, $route ),
			$method . ' ' . preg_replace( '#\(\?P<([^>]+)>[^)]*\)#', '{$1}', $route ),
			array_merge( $params, array( Confirm::FIELD => (string) $request->get_param( Confirm::FIELD ) ) )
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
	 * The key a direct REST write's confirmation is tracked under.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 */
	private function key( string $method, string $route ): string {
		return $method . ' ' . $route;
	}

	/**
	 * Stop tracking a claim once the route is about to run its callback.
	 *
	 * Commits only when the route's own callback is going to run.
	 *
	 * Keyed off the request, not this filter's `$route` argument: that one is the matched
	 * route pattern, `/wc/v3/orders/(?P<id>[\d]+)`, while the claim was keyed on the path the
	 * request actually asked for. Keying on the pattern untracked nothing, and the release
	 * below then re-armed the token after the write had run.
	 *
	 * @param mixed $dispatch_result Result, or null to let dispatch proceed.
	 * @param mixed $request         The request, as whatever reached this filter.
	 * @return mixed
	 */
	public function commit( $dispatch_result, $request ) {
		// A non-null result means a callback halted the request and the route's own callback
		// never runs, so the claim has to stay tracked for the release to give it back. This
		// runs last, which is the only reason it can see that.
		if ( null === $dispatch_result && $request instanceof WP_REST_Request ) {
			$this->confirm->commit( $this->key( strtoupper( (string) $request->get_method() ), (string) $request->get_route() ) );
		}
		return $dispatch_result;
	}

	/**
	 * Hand a confirmation back when the route refused the call before running it.
	 *
	 * Registered on two filters whose second argument differs, so only the first and third are
	 * read. A release with nothing tracked is a no-op, which is what makes that safe.
	 *
	 * @param mixed $response Response, or WP_Error.
	 * @param mixed $unused   Route handler, or the server, depending on the filter.
	 * @param mixed $request  The request, as whatever reached this filter.
	 * @return mixed
	 */
	public function release( $response, $unused, $request ) {
		if ( $request instanceof WP_REST_Request ) {
			$this->confirm->release( $this->key( strtoupper( (string) $request->get_method() ), (string) $request->get_route() ) );
		}
		return $response;
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
	public function is_agent_context(): bool {
		if ( $this->is_agent_credential() ) {
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
	private function is_agent_credential(): bool {
		$uuid = $GLOBALS['wp_rest_application_password_uuid'] ?? null;
		if ( ! is_string( $uuid ) || '' === $uuid ) {
			return false;
		}
		return in_array( $uuid, (array) get_option( self::OPTION, array() ), true );
	}
}
