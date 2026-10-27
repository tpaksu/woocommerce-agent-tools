<?php
/**
 * Wiring. Hooks the compiler into the abilities and REST lifecycles, and nothing else.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

namespace WooCommerce\AgentTools;

use WooCommerce\AgentTools\Ability\Cache;
use WooCommerce\AgentTools\Ability\Compiler;
use WooCommerce\AgentTools\Ability\Dedupe;
use WooCommerce\AgentTools\Ability\Factory;
use WooCommerce\AgentTools\Agent\Discovery;
use WooCommerce\AgentTools\Agent\Instructions;
use WooCommerce\AgentTools\Agent\Surface;
use WooCommerce\AgentTools\Route\Attribution;
use WooCommerce\AgentTools\Route\KnownShapes;
use WooCommerce\AgentTools\Route\Naming;
use WooCommerce\AgentTools\Route\Params;
use WooCommerce\AgentTools\Route\Policy;
use WooCommerce\AgentTools\Route\Schema;
use WooCommerce\AgentTools\Safety\Confirm;
use WooCommerce\AgentTools\Safety\Gate;
use WooCommerce\AgentTools\Safety\RestGuard;
use WooCommerce\AgentTools\Safety\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wiring. Hooks the compiler into the abilities and REST lifecycles, and nothing else.
 */
final class Plugin {

	/**
	 * The one built instance, so a second boot cannot register every hook twice.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Whether abilities have been registered for this request.
	 *
	 * @var bool
	 */
	private $registered = false;

	/**
	 * The Tools screen.
	 *
	 * @var Admin
	 */
	private $admin;

	/**
	 * The cached compiled surface.
	 *
	 * @var Cache
	 */
	private $cache;

	/**
	 * Extension discovery.
	 *
	 * @var Discovery
	 */
	private $discovery;

	/**
	 * Ability registration.
	 *
	 * @var Factory
	 */
	private $factory;

	/**
	 * The site-wide confirmation gate.
	 *
	 * @var Gate
	 */
	private $gate;

	/**
	 * The store's operating instructions.
	 *
	 * @var Instructions
	 */
	private $instructions;

	/**
	 * Payload shapes for undeclared routes.
	 *
	 * @var KnownShapes
	 */
	private $known_shapes;

	/**
	 * The REST-level confirmation gate.
	 *
	 * @var RestGuard
	 */
	private $rest_guard;

	/**
	 * The tool list refresh.
	 *
	 * @var Surface
	 */
	private $surface;

	/**
	 * Wire the collaborators this class calls.
	 *
	 * @param Admin        $admin        The Tools screen.
	 * @param Cache        $cache        The cached compiled surface.
	 * @param Discovery    $discovery    Extension discovery.
	 * @param Factory      $factory      Ability registration.
	 * @param Gate         $gate         The site-wide confirmation gate.
	 * @param Instructions $instructions The store's operating instructions.
	 * @param KnownShapes  $known_shapes Payload shapes for undeclared routes.
	 * @param RestGuard    $rest_guard   The REST-level confirmation gate.
	 * @param Surface      $surface      The tool list refresh.
	 */
	public function __construct(
		Admin $admin,
		Cache $cache,
		Discovery $discovery,
		Factory $factory,
		Gate $gate,
		Instructions $instructions,
		KnownShapes $known_shapes,
		RestGuard $rest_guard,
		Surface $surface
	) {
		$this->admin        = $admin;
		$this->cache        = $cache;
		$this->discovery    = $discovery;
		$this->factory      = $factory;
		$this->gate         = $gate;
		$this->instructions = $instructions;
		$this->known_shapes = $known_shapes;
		$this->rest_guard   = $rest_guard;
		$this->surface      = $surface;
	}

	/**
	 * Build the object graph and hook it up.
	 *
	 * The composition root. Static because the plugin bootstrap has no instance to call,
	 * and the only place in the plugin that uses `new`.
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}

		$attribution  = new Attribution();
		$confirm      = new Confirm();
		$discovery    = new Discovery();
		$instructions = new Instructions();
		$known_shapes = new KnownShapes();
		$params       = new Params();
		$policy       = new Policy();
		$schema       = new Schema();
		$secrets      = new Secrets();

		$naming     = new Naming( $attribution );
		$dispatcher = new Dispatcher( $secrets );
		$dedupe     = new Dedupe( $naming );
		$factory    = new Factory( $confirm, $dispatcher );
		$rest_guard = new RestGuard( $confirm, $dispatcher );
		$gate       = new Gate( $confirm, $rest_guard );
		$admin      = new Admin( $rest_guard );
		$compiler   = new Compiler( $attribution, $dedupe, $naming, $params, $policy, $schema );
		$cache      = new Cache( $compiler );
		$surface    = new Surface( $cache );

		self::$instance = new self(
			$admin,
			$cache,
			$discovery,
			$factory,
			$gate,
			$instructions,
			$known_shapes,
			$rest_guard,
			$surface
		);
		self::$instance->init();
	}

	/**
	 * Hook up.
	 */
	public function init(): void {
		// Before rest_api_init: if we will compile this request, make the route table complete first.
		add_action( 'plugins_loaded', array( $this, 'maybe_force_full_route_table' ), 1 );
		// And on every MCP request, because dispatch resolves the route again at call time.
		add_action( 'rest_api_init', array( $this, 'force_full_route_table_for_mcp' ), 1 );
		add_filter( 'mcp_adapter_default_server_config', array( $this, 'add_server_instructions' ) );
		$this->gate->init();
		$this->known_shapes->init();
		$this->rest_guard->init();
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		if ( is_admin() ) {
			$this->admin->init();
		}
		// Priority 20: after core and extensions have registered theirs, so dedupe can see them.
		add_action( 'wp_abilities_api_init', array( $this, 'register' ), 20 );
		add_action( 'abilities_api_init', array( $this, 'register' ), 20 );
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
	public function add_server_instructions( $config ) {
		if ( ! is_array( $config ) ) {
			return $config;
		}
		$config['server_description'] = $this->instructions->instructions();
		return $config;
	}

	/**
	 * Register the category derived abilities live in.
	 */
	public function register_category(): void {
		wp_register_ability_category(
			Config::CATEGORY,
			array(
				'label'       => 'Derived from REST routes',
				'description' => 'Abilities compiled from the REST routes of WooCommerce and the extensions installed on this store.',
			)
		);
		wp_register_ability_category(
			Config::DISCOVERY_CATEGORY,
			array(
				'label'       => 'Extension discovery',
				'description' => 'Searches for extensions that would provide a capability this store does not have.',
			)
		);
		wp_register_ability_category(
			Config::SURFACE_CATEGORY,
			array(
				'label'       => 'Tool list',
				'description' => 'Rebuilding the tool list when the store starts exposing something it did not before.',
			)
		);
	}

	/**
	 * WooCommerce skips registering a known namespace when the current request is for a
	 * different one, so a compile would see a partial route table and silently emit a
	 * fraction of the tools. If this request is going to compile, force them all to load.
	 */
	public function maybe_force_full_route_table(): void {
		$cached = get_transient( Config::CACHE_KEY );
		// Same test as `Cache::descriptors()`: an entry with a fingerprint but no descriptors
		// would skip the force here and then compile against a partial route table.
		if ( is_array( $cached ) && isset( $cached['fp'], $cached['d'] ) && $this->cache->fingerprint() === $cached['fp'] ) {
			return;
		}
		$this->force_full_route_table();
	}

	/**
	 * Load every namespace on a request to the MCP endpoint.
	 *
	 * A compile-time force is not enough: `check()` and `dispatch()` resolve the route
	 * again when a tool is called, on a request that may be reading a warm cache, and a
	 * deferred namespace makes a registered tool fail with "Permission denied" because
	 * its route is simply not in the table.
	 */
	public function force_full_route_table_for_mcp(): void {
		$route = isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) ? (string) ( $GLOBALS['wp']->query_vars['rest_route'] ?? '' ) : '';
		if ( ! str_starts_with( ltrim( $route, '/' ), 'mcp/' ) ) {
			return;
		}
		$this->force_full_route_table();
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
	private function force_full_route_table(): void {
		add_filter( 'wc_rest_should_load_namespace', '__return_true', 99 );
		add_filter( 'woocommerce_rest_should_lazy_load_namespace', '__return_false', 99 );
	}

	/**
	 * Compile (or read from cache) and register.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		foreach ( $this->cache->descriptors() as $d ) {
			$this->factory->register_one( $d );
		}

		$this->discovery->register_discovery_abilities();
		$this->surface->register_surface_abilities();
	}
}
