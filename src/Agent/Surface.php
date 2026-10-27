<?php
/**
 * The tool an agent calls when this store's capabilities have changed underneath it.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

namespace WooCommerce\AgentTools\Agent;

use WooCommerce\AgentTools\Ability\Cache;
use WooCommerce\AgentTools\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The tool an agent calls when this store's capabilities have changed underneath it.
 *
 * The compiled surface is cached against the active plugins and their versions, which an
 * accepted agreement or a connected account does not touch. WooCommerce Shipping goes from
 * two tools to several dozen on accepting its terms, with an identical fingerprint either side, so the
 * stale list is served for up to a day. Nothing in the cache can notice that, which leaves
 * the merchant's own word that they finished the task as the only signal there is.
 */
final class Surface {

	/**
	 * The cached compiled surface.
	 *
	 * @var Cache
	 */
	private $cache;

	/**
	 * Wire the collaborators this class calls.
	 *
	 * @param Cache $cache The cached compiled surface.
	 */
	public function __construct( Cache $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Register the refresh tool.
	 */
	public function register_surface_abilities(): void {
		wp_register_ability(
			'woocommerce/refresh-abilities',
			array(
				'label'               => 'Refresh the tool list',
				'description'         => 'Drops this store\'s compiled tool list, so the next discover-abilities rebuilds it from the store as it is now. Call this when the merchant reports finishing something in wp-admin that no tool here could do, such as accepting an agreement or connecting an account: those change what the store exposes without changing a plugin, and a plugin changing is the only thing the list watches. Then call discover-abilities again and compare what it returns against the list you were already holding. Changes no store data.',
				'category'            => Config::SURFACE_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					// An array, not an object: the adapter renders the JSON shape, while
					// `rest_validate_object_value_from_schema()` subscripts this value and
					// throws on anything that is not one.
					'properties' => array(),
				),
				'execute_callback'    => array( $this, 'refresh' ),
				'permission_callback' => function () {
					// The narrower role the Tools screen recommends connecting as is a Shop
					// manager, which has this and not `manage_options`.
					return current_user_can( 'manage_woocommerce' );
				},
				'meta'                => array(
					'public'       => true,
					'show_in_rest' => false,
					// Read-only as far as the store is concerned: this drops a cache entry and
					// changes nothing a merchant could see, so there is nothing to preview.
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);
	}

	/**
	 * Drop the compiled surface.
	 *
	 * Deliberately does not rebuild. A compile needs the whole route table, which
	 * `Plugin::maybe_force_full_route_table()` arranges only when it can see the cache is
	 * cold; rebuilding after a flush in the same request would walk the partial table that
	 * check already waved through, and cache a fraction of the store. The next request
	 * compiles through the path that gets this right.
	 *
	 * Reports no count of its own. The only number worth comparing is what
	 * `discover-abilities` returns, and the agent is already holding the earlier one.
	 *
	 * @param array<string,mixed>|null $input Ability input, unused.
	 * @return array<string,mixed>
	 */
	public function refresh( $input = null ): array {
		unset( $input );

		$this->cache->flush();

		return array(
			'flushed' => true,
			'next'    => 'Call discover-abilities again. It rebuilds the list, so it answers more slowly than usual, and what it returns is what this store exposes now.',
		);
	}
}
