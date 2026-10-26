<?php
/**
 * Wiring. Hooks the compiler into the abilities and REST lifecycles, and nothing else.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wiring. Hooks the compiler into the abilities and REST lifecycles, and nothing else.
 */
final class WC_Agent_Tools_Plugin {

	/**
	 * Hook up.
	 */
	public static function init(): void {
		// Before rest_api_init: if we will compile this request, make the route table complete first.
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_force_full_route_table' ), 1 );
		// And on every MCP request, because dispatch resolves the route again at call time.
		add_action( 'rest_api_init', array( __CLASS__, 'force_full_route_table_for_mcp' ), 1 );
		add_filter( 'mcp_adapter_default_server_config', array( __CLASS__, 'add_server_instructions' ) );
		WC_Agent_Tools_Gate::init();
		WC_Agent_Tools_Known_Shapes::init();
		WC_Agent_Tools_Rest_Guard::init();
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		if ( is_admin() ) {
			WC_Agent_Tools_Admin::init();
		}
		// Priority 20: after core and extensions have registered theirs, so dedupe can see them.
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ), 20 );
		add_action( 'abilities_api_init', array( __CLASS__, 'register' ), 20 );
	}

	/**
	 * Put the store's operating instructions in the MCP handshake.
	 *
	 * The adapter passes `server_description` through as the `instructions` field of the
	 * initialize response, which clients place in front of the model. It is advisory: it
	 * shapes what an agent does by default, and bounds nothing. The enforceable control
	 * is the capability of the user the connection authenticates as.
	 *
	 * @param mixed $config Default server config, as whatever an earlier callback returned.
	 * @return mixed
	 */
	public static function add_server_instructions( $config ) {
		if ( ! is_array( $config ) ) {
			return $config;
		}
		$config['server_description'] = WC_Agent_Tools_Instructions::instructions();
		return $config;
	}

	/**
	 * Register the category derived abilities live in.
	 */
	public static function register_category(): void {
		wp_register_ability_category(
			WC_Agent_Tools_Config::CATEGORY,
			array(
				'label'       => 'Derived from REST routes',
				'description' => 'Abilities compiled from the REST routes of WooCommerce and the extensions installed on this store.',
			)
		);
		wp_register_ability_category(
			WC_Agent_Tools_Config::DISCOVERY_CATEGORY,
			array(
				'label'       => 'Extension discovery',
				'description' => 'Searches for extensions that would provide a capability this store does not have.',
			)
		);
	}

	/**
	 * WooCommerce skips registering a known namespace when the current request is for a
	 * different one, so a compile would see a partial route table and silently emit a
	 * fraction of the tools. If this request is going to compile, force them all to load.
	 */
	public static function maybe_force_full_route_table(): void {
		$cached = get_transient( WC_Agent_Tools_Config::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['fp'] ) && WC_Agent_Tools_Cache::fingerprint() === $cached['fp'] ) {
			return;
		}
		self::force_full_route_table();
	}

	/**
	 * Load every namespace on a request to the MCP endpoint.
	 *
	 * A compile-time force is not enough: `check()` and `dispatch()` resolve the route
	 * again when a tool is called, on a request that may be reading a warm cache, and a
	 * deferred namespace makes a registered tool fail with "Permission denied" because
	 * its route is simply not in the table.
	 */
	public static function force_full_route_table_for_mcp(): void {
		$route = isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) ? (string) ( $GLOBALS['wp']->query_vars['rest_route'] ?? '' ) : '';
		if ( ! str_starts_with( ltrim( $route, '/' ), 'mcp/' ) ) {
			return;
		}
		self::force_full_route_table();
	}

	/**
	 * Defeat both namespace gates, so the route table is the whole store.
	 *
	 * `wc_rest_should_load_namespace()` guards wc-admin and the versioned namespaces.
	 * Since WooCommerce 10.3.0 `lazy_load_namespace()` is a second, independent gate
	 * that defers a namespace until a request asks for it by name; an MCP request never
	 * does, which leaves wc-analytics at 10 of its routes.
	 *
	 * @see \Automattic\WooCommerce\Admin\API\Init::rest_api_init()
	 */
	private static function force_full_route_table(): void {
		add_filter( 'wc_rest_should_load_namespace', '__return_true', 99 );
		add_filter( 'woocommerce_rest_should_lazy_load_namespace', '__return_false', 99 );
	}

	/**
	 * Compile (or read from cache) and register.
	 */
	public static function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		foreach ( WC_Agent_Tools_Cache::descriptors() as $d ) {
			WC_Agent_Tools_Ability_Factory::register_one( $d );
		}

		WC_Agent_Tools_Discovery::register_discovery_abilities();
	}
}
