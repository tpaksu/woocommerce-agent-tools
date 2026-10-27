<?php
/**
 * Stubs for the MCP Adapter plugin, for static analysis only.
 *
 * The adapter is a hard dependency declared in `Requires Plugins`, but it ships no stub
 * package, so PHPStan resolves every call on it to `mixed` and stops checking there. These
 * signatures are copied from the plugin's own source (`includes/Core/McpAdapter.php`,
 * `includes/Core/McpServer.php`, `includes/Abilities/McpAbilityExposure.php`) against
 * version 0.7.1.
 *
 * Return types are given as `@return` rather than native, matching `wordpress-stubs`: a
 * native return type on an empty body makes every editor report that not all paths return
 * a value. PHPStan reads the docblock either way. Never loaded at runtime.
 *
 * Keep this to the surface this plugin actually calls. A stub that guesses at a method it
 * does not use is a claim nobody checks.
 *
 * @package woocommerce-agent-tools
 */

namespace WP\MCP\Core {

	final class McpAdapter {

		/**
		 * @return self
		 */
		public static function instance()
		{
		}

		/**
		 * @param string $server_id Server id.
		 * @return McpServer|null
		 */
		public function get_server( string $server_id )
		{
		}

		/**
		 * @return array<string,McpServer>
		 */
		public function get_servers()
		{
		}
	}

	class McpServer {

		/**
		 * @return string
		 */
		public function get_server_route_namespace()
		{
		}

		/**
		 * @return string
		 */
		public function get_server_route()
		{
		}
	}
}

namespace WP\MCP\Abilities {

	use WP_Ability;

	final class McpAbilityExposure {

		/**
		 * @param WP_Ability $ability The ability.
		 * @return bool
		 */
		public static function is_public( WP_Ability $ability )
		{
		}
	}
}
