<?php
/**
 * Stubs for the MCP Adapter plugin, for static analysis only.
 *
 * The adapter is a hard dependency declared in `Requires Plugins`, but it ships no stub
 * package, so PHPStan resolves every call on it to `mixed` and stops checking there. These
 * signatures are copied from the plugin's own source (`includes/Core/McpAdapter.php`,
 * `includes/Core/McpServer.php`, `includes/Abilities/McpAbilityExposure.php`) against
 * version 0.7.1. They are never loaded at runtime.
 *
 * Keep this to the surface this plugin actually calls. A stub that guesses at a method it
 * does not use is a claim nobody checks.
 *
 * @package woocommerce-agent-tools
 */

// phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.ClassComment.Missing, Generic.Files.OneObjectStructurePerFile.MultipleFound, WordPress.Files.FileName.InvalidClassFileName

namespace WP\MCP\Core {

	final class McpAdapter {

		public static function instance(): self {}

		public function get_server( string $server_id ): ?McpServer {}

		/**
		 * @return array<string,McpServer>
		 */
		public function get_servers(): array {}
	}

	class McpServer {

		public function get_server_route_namespace(): string {}

		public function get_server_route(): string {}
	}
}

namespace WP\MCP\Abilities {

	use WP_Ability;

	final class McpAbilityExposure {

		public static function is_public( WP_Ability $ability ): bool {}
	}
}
