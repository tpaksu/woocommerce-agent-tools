<?php
/**
 * Searches for extensions that would provide a capability this store does not have. The only abilities here that are not derived, because neither search has a REST route to derive.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

namespace WooCommerce\AgentTools\Agent;

use WooCommerce\AgentTools\Config;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Searches for extensions that would provide a capability this store does not have. The only abilities here that are not derived, because neither search has a REST route to derive.
 */
final class Discovery {

	/**
	 * Abilities the compiler adds itself, because no REST route exists to derive them.
	 *
	 * The curated feeds answer "what could this store add" by category. Keyword search has
	 * no route to derive in either direction: the admin app calls woocommerce.com straight
	 * from the browser, and `plugins_api()` is a PHP function with no endpoint in front of
	 * it. Capabilities mirror the screens a merchant would use instead, so the rule that an
	 * agent reaches what the user reaches in wp-admin still holds.
	 */
	public function register_discovery_abilities(): void {
		wp_register_ability(
			'woocommerce/marketplace-search',
			array(
				'label'               => 'Search the WooCommerce Marketplace',
				'description'         => 'Searches WooCommerce.com for extensions matching a term, to recommend when no installed plugin does what was asked. Sends the term to WooCommerce.com. Returns titles, prices, ratings and links, and says which results are already installed. Buys and installs nothing.',
				'category'            => Config::DISCOVERY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'term'     => array(
							'type'        => 'string',
							'description' => 'What the merchant wants to do, for example "subscriptions" or "booking appointments".',
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 10,
							'minimum' => 1,
							'maximum' => 25,
						),
					),
					'required'   => array( 'term' ),
				),
				'execute_callback'    => array( $this, 'search_marketplace' ),
				'permission_callback' => function () {
					// The Extensions screen this stands in for is 'manage_woocommerce'.
					return current_user_can( 'manage_woocommerce' );
				},
				'meta'                => array(
					'public'       => true,
					'show_in_rest' => false,
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);

		wp_register_ability(
			'woocommerce/plugin-directory-search',
			array(
				'label'               => 'Search the WordPress.org plugin directory',
				'description'         => 'Searches the WordPress.org plugin directory for a term, to recommend a free plugin when no installed one does what was asked. Sends the term to WordPress.org. Returns names, install counts, ratings and links, and says which results are already installed. Installs nothing.',
				'category'            => Config::DISCOVERY_CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'term'     => array(
							'type'        => 'string',
							'description' => 'What the merchant wants to do, for example "wishlist" or "pdf invoices".',
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 10,
							'minimum' => 1,
							'maximum' => 25,
						),
					),
					'required'   => array( 'term' ),
				),
				'execute_callback'    => array( $this, 'search_plugin_directory' ),
				'permission_callback' => function () {
					// The Add Plugin screen this stands in for is 'install_plugins'.
					return current_user_can( 'install_plugins' );
				},
				'meta'                => array(
					'public'       => true,
					'show_in_rest' => false,
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);
	}

	/**
	 * Search WooCommerce.com for extensions.
	 *
	 * @param array<string,mixed>|null $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function search_marketplace( $input = null ) {
		$input = (array) $input;
		$term  = trim( (string) ( $input['term'] ?? '' ) );
		if ( '' === $term ) {
			return new WP_Error( 'wcat_missing_term', 'A search term is required.' );
		}
		$per_page = max( 1, min( 25, (int) ( $input['per_page'] ?? 10 ) ) );

		$key      = Config::SEARCH_CACHE_PREFIX . 'mp_' . md5( $term );
		$products = get_transient( $key );
		if ( ! is_array( $products ) ) {
			$response = wp_safe_remote_get(
				add_query_arg( array( 'term' => $term ), Config::MARKETPLACE_SEARCH_URL ),
				array( 'timeout' => 15 )
			);
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) || ! isset( $body['products'] ) || ! is_array( $body['products'] ) ) {
				return new WP_Error( 'wcat_marketplace_unreadable', 'WooCommerce.com returned no usable results.' );
			}
			$products = $body['products'];
			set_transient( $key, $products, Config::SEARCH_CACHE_TTL );
		}

		$installed = $this->installed_slugs();
		$results   = array();
		foreach ( array_slice( $products, 0, $per_page ) as $product ) {
			$product   = (array) $product;
			$slug      = (string) ( $product['slug'] ?? '' );
			$results[] = array(
				'slug'        => $slug,
				'title'       => $this->plain( (string) ( $product['title'] ?? '' ) ),
				'description' => $this->plain( (string) ( $product['excerpt'] ?? '' ) ),
				'price'       => $this->plain( (string) ( $product['price'] ?? '' ) ),
				'vendor'      => $this->plain( (string) ( $product['vendor_name'] ?? '' ) ),
				'rating'      => $product['rating'] ?? null,
				'url'         => (string) ( $product['link'] ?? '' ),
				'installed'   => isset( $installed[ $slug ] ),
			);
		}

		return array(
			'source'  => 'woocommerce.com',
			'term'    => $term,
			'total'   => count( $products ),
			'results' => $results,
		);
	}

	/**
	 * Search the WordPress.org plugin directory.
	 *
	 * @param array<string,mixed>|null $input Ability input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function search_plugin_directory( $input = null ) {
		$input = (array) $input;
		$term  = trim( (string) ( $input['term'] ?? '' ) );
		if ( '' === $term ) {
			return new WP_Error( 'wcat_missing_term', 'A search term is required.' );
		}
		$per_page = max( 1, min( 25, (int) ( $input['per_page'] ?? 10 ) ) );

		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		$api = plugins_api(
			'query_plugins',
			array(
				'search'   => $term,
				'per_page' => $per_page,
				'fields'   => array(
					'short_description' => true,
					'banners'           => false,
					'compatibility'     => false,
					'contributors'      => false,
					'description'       => false,
					'icons'             => false,
					'ratings'           => false,
					'reviews'           => false,
					'sections'          => false,
					'tags'              => false,
				),
			)
		);
		if ( is_wp_error( $api ) ) {
			return $api;
		}

		$installed = $this->installed_slugs();
		$results   = array();
		foreach ( (array) ( $api->plugins ?? array() ) as $plugin ) {
			$plugin    = (array) $plugin;
			$slug      = (string) ( $plugin['slug'] ?? '' );
			$results[] = array(
				'slug'            => $slug,
				'name'            => $this->plain( (string) ( $plugin['name'] ?? '' ) ),
				'description'     => $this->plain( (string) ( $plugin['short_description'] ?? '' ) ),
				'author'          => $this->plain( (string) ( $plugin['author'] ?? '' ) ),
				'active_installs' => (int) ( $plugin['active_installs'] ?? 0 ),
				'rating'          => (int) ( $plugin['rating'] ?? 0 ),
				'requires_php'    => (string) ( $plugin['requires_php'] ?? '' ),
				'tested'          => (string) ( $plugin['tested'] ?? '' ),
				'url'             => 'https://wordpress.org/plugins/' . $slug . '/',
				'installed'       => isset( $installed[ $slug ] ),
			);
		}

		return array(
			'source'  => 'wordpress.org',
			'term'    => $term,
			'total'   => (int) ( $api->info['results'] ?? count( $results ) ),
			'results' => $results,
		);
	}

	/**
	 * Directory slugs of every plugin present on disk, so a suggestion can say whether the
	 * store already has it.
	 *
	 * @return array<string,bool>
	 */
	private function installed_slugs(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$slugs = array();
		foreach ( array_keys( get_plugins() ) as $file ) {
			$slugs[ dirname( (string) $file ) ] = true;
		}
		unset( $slugs['.'] );
		return $slugs;
	}

	/**
	 * Entity-decoded, tag-stripped text. Both feeds return HTML in fields a model reads as
	 * prose, and a price arrives as `&#36;279`.
	 *
	 * @param string $text Raw text.
	 */
	private function plain( string $text ): string {
		return trim( wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );
	}
}
