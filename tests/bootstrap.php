<?php
/**
 * Enough of WordPress to exercise the confirmation gate, and no more.
 *
 * The gate is logic over a key-value store with expiry, so it is tested against a model of
 * that store rather than against WordPress. The model owns its own clock, so expiry is
 * deterministic, and it reproduces the two transient behaviours that caused real defects:
 * `delete_transient()` ignores the timeout, and it reports whether a row was there.
 *
 * It models the database path, which is one of WordPress's two backends. With a persistent
 * object cache `wp_cache_delete()` does respect expiry, and the liveness read catches an
 * expired token before the delete either way, so the claimed/lost/dead decisions come out the
 * same. Verified against wp-includes/option.php on WordPress 7.1.3 and no other version.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

// Every file under src/ exits unless this is set.
define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/**
 * The option table, with a clock the tests control.
 */
final class WPOptions {

	/** @var array<string,mixed> */
	public static $options = array();

	/** @var int */
	public static $now = 1700000000;

	/** @var int The connected user, which the token is bound to. */
	public static $user_id = 1;

	/** @var array<string,callable[]> */
	public static $filters = array();

	/** @var string[] Keys whose delete fails, to stand in for a storage failure. */
	public static $undeletable = array();

	/**
	 * Keys that read as live once and are gone afterwards, which is what a race loser sees:
	 * it read before the winner's delete and attempts its own delete after it.
	 *
	 * @var string[]
	 */
	public static $vanish_after_read = array();

	public static function reset(): void {
		self::$options           = array();
		self::$now               = 1700000000;
		self::$user_id           = 1;
		self::$filters           = array();
		self::$undeletable       = array();
		self::$vanish_after_read = array();
	}

	public static function travel( int $seconds ): void {
		self::$now += $seconds;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error.
	 */
	class WP_Error {

		/** @var string */
		private $code;

		/** @var string */
		private $message;

		/** @var array<string,mixed> */
		private $data;

		/**
		 * @param string              $code    Code.
		 * @param string              $message Message.
		 * @param array<string,mixed> $data    Data.
		 */
		public function __construct( string $code = '', string $message = '', array $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		/** @return array<string,mixed> */
		public function get_error_data() {
			return $this->data;
		}

		public function has_errors(): bool {
			return '' !== $this->code;
		}
	}
}

/**
 * @param mixed $thing Thing.
 */
function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

/**
 * @param string $key Transient key.
 * @return mixed
 */
function get_transient( string $key ) {
	$timeout = WPOptions::$options[ '_transient_timeout_' . $key ] ?? null;
	if ( null !== $timeout && (int) $timeout < WPOptions::$now ) {
		// What WordPress does: an expired read deletes both rows and reports a miss.
		unset( WPOptions::$options[ '_transient_timeout_' . $key ], WPOptions::$options[ '_transient_' . $key ] );
		return false;
	}

	$value = WPOptions::$options[ '_transient_' . $key ] ?? false;

	$vanishing = array_search( $key, WPOptions::$vanish_after_read, true );
	if ( false !== $vanishing ) {
		unset( WPOptions::$vanish_after_read[ $vanishing ] );
		unset( WPOptions::$options[ '_transient_timeout_' . $key ], WPOptions::$options[ '_transient_' . $key ] );
	}

	return $value;
}

/**
 * @param string $key   Transient key.
 * @param mixed  $value Value.
 * @param int    $ttl   Seconds, 0 for no expiry.
 */
function set_transient( string $key, $value, int $ttl = 0 ): bool {
	if ( $ttl > 0 ) {
		WPOptions::$options[ '_transient_timeout_' . $key ] = WPOptions::$now + $ttl;
	}
	// `update_option()` reports false when the value is unchanged, and `set_transient()`
	// passes that through.
	$unchanged = array_key_exists( '_transient_' . $key, WPOptions::$options )
		&& WPOptions::$options[ '_transient_' . $key ] === $value;
	WPOptions::$options[ '_transient_' . $key ] = $value;

	return ! $unchanged;
}

/**
 * Deletes without consulting the timeout, and reports whether the value row existed. Both
 * are what WordPress does, and both have mattered.
 *
 * @param string $key Transient key.
 */
function delete_transient( string $key ): bool {
	if ( in_array( $key, WPOptions::$undeletable, true ) ) {
		return false;
	}
	$existed = array_key_exists( '_transient_' . $key, WPOptions::$options );
	unset( WPOptions::$options[ '_transient_' . $key ] );
	// WordPress drops the timeout row only when the value row was there, so an orphan
	// timeout can outlive its value until the daily cron.
	if ( $existed ) {
		unset( WPOptions::$options[ '_transient_timeout_' . $key ] );
	}

	return $existed;
}

function get_current_user_id(): int {
	return WPOptions::$user_id;
}

/**
 * @param string $scheme Scheme.
 */
function wp_salt( string $scheme = 'auth' ): string {
	return 'test-salt-' . $scheme;
}

/**
 * @param mixed $data  Data.
 * @param int   $flags Flags.
 * @return string|false
 */
function wp_json_encode( $data, int $flags = 0 ) {
	$json = json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	if ( false !== $json ) {
		return $json;
	}
	// `_wp_json_sanity_check()` strips invalid UTF-8 and re-encodes, so two payloads that
	// differ only in bad bytes come back identical. It gives up past the depth limit.
	if ( JSON_ERROR_UTF8 !== json_last_error() ) {
		return false;
	}
	array_walk_recursive(
		$data,
		function ( &$value ) {
			if ( is_string( $value ) ) {
				$value = preg_replace( '/[^\x00-\x7F]/', '?', $value );
			}
		}
	);

	return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
}

/**
 * @param string   $hook     Hook.
 * @param callable $callback Callback.
 */
function add_filter( string $hook, callable $callback ): bool {
	WPOptions::$filters[ $hook ][] = $callback;

	return true;
}

/**
 * @param string $hook  Hook.
 * @param mixed  $value Value.
 * @return mixed
 */
function apply_filters( string $hook, $value ) {
	$args = array_slice( func_get_args(), 1 );
	foreach ( WPOptions::$filters[ $hook ] ?? array() as $callback ) {
		$args[0] = $callback( ...$args );
	}

	return $args[0];
}
