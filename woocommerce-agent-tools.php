<?php
/**
 * Plugin Name: WooCommerce Agent Tools
 * Plugin URI: https://github.com/woocommerce/woocommerce
 * Description: Compiles WordPress abilities from plugin REST routes, so an MCP agent sees what the store can actually do. Prototype for internal evaluation.
 * Version: 0.5.0
 * Author: WooCommerce
 * Author URI: https://woocommerce.com/
 *
 * Text Domain: woocommerce-agent-tools
 * Domain Path: /languages/
 *
 * Requires PHP: 7.4
 *
 * Requires at least: 6.9
 *
 * WC requires at least: 10.0
 * WC tested up to: 11.4
 *
 * Requires Plugins: woocommerce, mcp-adapter
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

use WooCommerce\AgentTools\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declare the plugin storage-agnostic, so HPOS stores do not flag it.
 *
 * `custom_order_tables` is the one feature that treats silence as incompatible, and this plugin
 * never reads or writes an order: every call is dispatched through the route's own controller,
 * whatever that controller stores in. Declared outside the dependency check below, because the
 * plugin stays listed on the HPOS screen even when it compiles no abilities.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

$wcat_autoloader = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $wcat_autoloader ) ) {
	require_once $wcat_autoloader;
}

/**
 * Dependencies that are missing, by name.
 *
 * `Requires Plugins` covers WooCommerce and the MCP Adapter on WordPress 6.5 and up, but it
 * cannot express a core API, and it does not stop someone activating this plugin another way.
 * Failing silently is the wrong answer on a store that is not the author's.
 *
 * @return string[]
 */
function wcat_missing_dependencies(): array {
	$missing = array();
	// A release ships the autoloader; a checkout has one only after `composer install`.
	if ( ! class_exists( Plugin::class ) ) {
		$missing[] = 'its own class autoloader (run `composer install`, or install a built release)';
	}
	if ( ! function_exists( 'wp_register_ability' ) ) {
		$missing[] = 'the WordPress Abilities API (WordPress 6.9 or newer)';
	}
	if ( ! class_exists( 'WooCommerce' ) ) {
		$missing[] = 'WooCommerce';
	}
	if ( ! class_exists( 'WP\MCP\Core\McpAdapter' ) ) {
		$missing[] = 'the MCP Adapter plugin';
	}
	return $missing;
}

add_action(
	'plugins_loaded',
	function () {
		$missing = wcat_missing_dependencies();
		if ( empty( $missing ) ) {
			Plugin::boot();
			return;
		}

		add_action(
			'admin_notices',
			function () use ( $missing ) {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				printf(
					'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
					esc_html__( 'WooCommerce Agent Tools is inactive.', 'woocommerce-agent-tools' ),
					esc_html(
						sprintf(
							/* translators: %s: comma-separated list of missing dependencies. */
							__( 'It needs %s. No abilities were compiled.', 'woocommerce-agent-tools' ),
							implode( ', ', $missing )
						)
					)
				);
			}
		);
	},
	0
);
