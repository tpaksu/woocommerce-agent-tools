<?php
/**
 * Policy data the compiler reads: which namespaces and routes to skip, which fields hold secrets, and the cache keys.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

namespace WooCommerce\AgentTools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Policy data the compiler reads: which namespaces and routes to skip, which fields hold secrets, and the cache keys.
 */
final class Config {

	const CACHE_KEY = 'wcat_descriptors';

	const CATEGORY = 'woocommerce-derived';

	const DISCOVERY_CATEGORY = 'woocommerce-discovery';

	const SURFACE_CATEGORY = 'woocommerce-surface';

	const MARKETPLACE_SEARCH_URL = 'https://woocommerce.com/wp-json/wccom-extensions/1.0/search';

	const SEARCH_CACHE_PREFIX = 'wcat_search_';

	const SEARCH_CACHE_TTL = 900;

	/**
	 * Ability namespaces the gate never holds.
	 *
	 * `mcp-adapter/execute-ability` is itself annotated destructive, so holding it would stop
	 * every call on the store, including the confirmed retry. Its job is to forward a call to
	 * the ability underneath, and that ability is where the gate belongs.
	 *
	 * @var string[]
	 */
	const GATE_EXEMPT_NAMESPACES = array(
		'mcp-adapter',
	);

	/**
	 * Namespace precedence when two routes compile to the same tool name. Earlier wins.
	 *
	 * `wc-analytics` re-registers most of the `wc/v3` controllers for the admin app, so both
	 * reduce to the same resource and the same name. Without an order the winner is whichever
	 * the route table happens to list first, which handed every merchant CRUD tool to the
	 * analytics namespace. A namespace absent from this list sorts after every one present.
	 *
	 * @var string[]
	 */
	const NS_PREFER = array(
		'wc/v3',
		'wc-analytics',
		'wc-admin',
	);

	/**
	 * Namespaces excluded regardless of which plugin's directory the handler sits in.
	 * This is the route-level tiebreaker the design calls for: it survives vendoring.
	 *
	 * @var string[]
	 */
	const NS_DENY = array(
		'jetpack',
		'wp',
		'oembed',
		'wp-site-health',
		'wp-block-editor',
		'wp-abilities',
		'mcp',
		'batch',
		'agents-manager',
		'wccom-site',
		'wc-telemetry',
		'woocommerce-ai',
	);

	/**
	 * Route prefixes that are admin-UI state rather than merchant operations.
	 *
	 * @var string[]
	 */
	const ROUTE_DENY = array(
		'/wc-admin/onboarding/tasks',
		'/wc-admin/options',
		'/wc-admin/notes',
		'/wc-admin/settings/payments',
		'/wc-admin/mobile-app',
		'/woocommerce/mcp',
		'/woocommerce-ai/mcp',
		// The Store API is the shopper's cart and checkout, scoped to their own session. An
		// admin credential cannot read another person's order through it, so `order-get` only
		// ever answered "Permission denied" while shadowing the `orders-get` that works, and
		// `checkout-update` would edit a shopper's live checkout. Not a merchant surface.
		'/wc/store',
	);

	/**
	 * Query-by-POST: routes that mutate nothing but use POST because the query payload
	 * will not fit in a URL. HTTP method cannot express this, so it is declared. Both
	 * entries were verified read-only by reading their handlers.
	 *
	 * @var array<string,bool>
	 */
	const QUERY_BY_POST = array(
		'/wcshipping/v1/label/rate' => true,
		'/wc/v3/orders/(?P<order_id>[\\d]+)/refunds/preview' => true,
	);

	/**
	 * Prefix of the opaque reference an agent sees instead of a secret.
	 */
	const SECRET_PREFIX = 'wcat_secret:';

	/**
	 * How long a reference stays resolvable, in seconds.
	 */
	const SECRET_TTL = 300;

	/**
	 * Transient holding the reference map. It stores where each secret lives,
	 * never the secret itself.
	 */
	const SECRET_MAP_KEY = 'wcat_secret_map';

	/**
	 * Field names treated as secret on routes that do not type their fields.
	 * An exact-match opt-in list, not a pattern: a pattern cannot be validated
	 * against a store whose credentials are unset, and over-redaction breaks tools.
	 *
	 * @var string[]
	 */
	const SECRET_FIELDS = array(
		'secret_key',
		'test_secret_key',
		'webhook_secret',
		'test_webhook_secret',
		'client_secret',
		'api_secret',
		'private_key',
	);

	/**
	 * The type of a property whose type a route never stated. Declaring the union rather
	 * than omitting `type` matters: WordPress's validator emits an "Undefined array key
	 * type" warning on every call for a property without one.
	 *
	 * @var string[]
	 */
	const ANY_TYPE = array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' );

	/**
	 * Parameters every REST collection carries. They say nothing about what a route
	 * accepts, so they do not count towards a route declaring its own inputs.
	 *
	 * @var string[]
	 */
	const BOILERPLATE_ARGS = array( 'context', 'page', 'per_page', 'offset', 'order', 'orderby', '_fields', '_links', '_embed' );
}
