<?php
/**
 * The operating instructions this store puts in front of a connecting agent, read from agents.md.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

namespace WooCommerce\AgentTools\Agent;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The operating instructions this store puts in front of a connecting agent, read from agents.md.
 */
final class Instructions {

	/**
	 * The instructions text, memoized for the request.
	 *
	 * @var string|null
	 */
	private $text = null;

	/**
	 * Where instructions are looked for, nearest first.
	 *
	 * @return string[]
	 */
	private function paths(): array {
		return array(
			WP_CONTENT_DIR . '/agents.md',
			// src/Agent/ sits two levels under the plugin root, where the shipped agents.md is.
			dirname( __DIR__, 2 ) . '/agents.md',
		);
	}

	/**
	 * The instructions text, from agents.md.
	 *
	 * The plugin ships a default. A store that wants to say something else drops its own
	 * agents.md in wp-content, which wins and survives a plugin update.
	 *
	 * A candidate counts only once it has yielded text. Breaking on `is_readable()` alone
	 * sent an empty string to every connecting agent when the file vanished between the
	 * check and the read, which is a silent failure in the one place a store is explaining
	 * itself.
	 */
	public function instructions(): string {
		if ( null === $this->text ) {
			$this->text = '';
			foreach ( $this->paths() as $path ) {
				if ( ! is_readable( $path ) ) {
					continue;
				}
				$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file shipped with the plugin, read once per request.
				if ( ! is_string( $contents ) || '' === trim( $contents ) ) {
					continue;
				}
				$this->text = trim( $contents );
				break;
			}
		}

		/**
		 * Filters the instructions this store sends to connecting agents.
		 *
		 * @since 0.1.0
		 *
		 * @param string $text The instructions.
		 */
		return (string) apply_filters( 'woocommerce_agent_tools_server_instructions', $this->text );
	}
}
