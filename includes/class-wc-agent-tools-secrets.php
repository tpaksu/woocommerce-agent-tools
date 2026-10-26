<?php
/**
 * Replaces credentials in a response with opaque references, and resolves a reference back when it is passed to the field it came from.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces credentials in a response with opaque references, and resolves a reference back when it is passed to the field it came from.
 */
final class WC_Agent_Tools_Secrets {

	/**
	 * Suspends redaction while a reference is being resolved from its source.
	 *
	 * @var bool
	 */
	private static $resolving = false;

	/**
	 * Replace secrets in a response with opaque references.
	 *
	 * A secret is a field the route itself typed as a password, or one named in
	 * SECRET_FIELDS. The reference records where the value came from, never the
	 * value, so nothing is copied anywhere by redacting.
	 *
	 * @param array<string,mixed> $data    Response data.
	 * @param string              $ability Ability the data came from.
	 * @param string              $owner   Owning plugin slug.
	 * @return array<string,mixed>
	 */
	public static function redact_secrets( array $data, string $ability, string $owner ): array {
		if ( self::$resolving || '' === $ability ) {
			return $data;
		}
		$map = self::secret_map();
		self::walk_redact( $data, array(), $ability, $owner, $map );
		self::store_secret_map( $map );
		return $data;
	}

	/**
	 * Recursive half of redact_secrets().
	 *
	 * @param array<array-key,mixed> $node    Node being walked, by reference.
	 * @param array<int,string>      $pointer Path from the response root.
	 * @param string                 $ability Ability the data came from.
	 * @param string                 $owner   Owning plugin slug.
	 * @param array<string,mixed>    $map     Reference map, by reference.
	 */
	private static function walk_redact( array &$node, array $pointer, string $ability, string $owner, array &$map ): void {
		// A field the route typed as a password: { type: password, value: ... }.
		if ( isset( $node['type'], $node['value'] ) && 'password' === $node['type'] && is_string( $node['value'] ) && '' !== $node['value'] ) {
			$field         = (string) ( end( $pointer ) ? end( $pointer ) : 'value' );
			$node['value'] = self::mint_secret( $ability, array_merge( $pointer, array( 'value' ) ), $field, $owner, $map );
			return;
		}

		foreach ( $node as $key => &$value ) {
			$here = array_merge( $pointer, array( (string) $key ) );
			if ( is_array( $value ) ) {
				self::walk_redact( $value, $here, $ability, $owner, $map );
				continue;
			}
			// REST responses carry stdClass as readily as arrays, and a secret inside one
			// would otherwise be walked past in silence.
			if ( is_object( $value ) ) {
				$inner = (array) $value;
				self::walk_redact( $inner, $here, $ability, $owner, $map );
				$value = (object) $inner;
				continue;
			}
			if ( is_string( $key ) && is_string( $value ) && '' !== $value
				&& in_array( strtolower( $key ), WC_Agent_Tools_Config::SECRET_FIELDS, true ) ) {
				$value = self::mint_secret( $ability, $here, (string) $key, $owner, $map );
			}
		}
	}

	/**
	 * Record where a secret lives and return the reference that stands in for it.
	 *
	 * @param string              $ability Source ability.
	 * @param array<int,string>   $pointer Path to the value within that ability's response.
	 * @param string              $field   Field name the value was read from.
	 * @param string              $owner   Owning plugin slug.
	 * @param array<string,mixed> $map     Reference map, by reference.
	 */
	private static function mint_secret( string $ability, array $pointer, string $field, string $owner, array &$map ): string {
		$handle         = WC_Agent_Tools_Config::SECRET_PREFIX . wp_generate_password( 24, false );
		$map[ $handle ] = array(
			'ability' => $ability,
			'pointer' => $pointer,
			'field'   => $field,
			'owner'   => $owner,
			'expires' => time() + WC_Agent_Tools_Config::SECRET_TTL,
		);
		return $handle;
	}

	/**
	 * Substitute real values back in for references the agent passed.
	 *
	 * A reference resolves only into a parameter with the same name it was minted
	 * from, on a route belonging to the same plugin. Without both, a reference read
	 * from one field could be passed as any other parameter of any other route and
	 * carry the secret out with it.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @param string              $owner Owning plugin of the route being called.
	 * @return array<string,mixed>
	 */
	public static function resolve_secrets( array $input, string $owner ): array {
		$map = self::secret_map();
		if ( ! $map ) {
			return $input;
		}
		self::walk_resolve( $input, $map, $owner );
		return $input;
	}

	/**
	 * Recursive half of resolve_secrets(). Matches on the immediate key a reference
	 * sits under, at any depth, so a nested `settings.secret_key` resolves as readily
	 * as a top-level one.
	 *
	 * @param array<array-key,mixed> $node  Node being walked, by reference.
	 * @param array<string,mixed>    $map   Reference map.
	 * @param string                 $owner Owning plugin of the route being called.
	 */
	private static function walk_resolve( array &$node, array $map, string $owner ): void {
		foreach ( $node as $key => &$value ) {
			if ( is_array( $value ) ) {
				self::walk_resolve( $value, $map, $owner );
				continue;
			}
			if ( is_object( $value ) ) {
				$inner = (array) $value;
				self::walk_resolve( $inner, $map, $owner );
				$value = (object) $inner;
				continue;
			}
			if ( ! is_string( $value ) || 0 !== strpos( $value, WC_Agent_Tools_Config::SECRET_PREFIX ) ) {
				continue;
			}
			$entry = isset( $map[ $value ] ) ? $map[ $value ] : null;
			if ( ! is_array( $entry ) || $entry['expires'] < time() ) {
				continue;
			}
			// The only gate: a reference resolves into a parameter with the same name it
			// was read from. Binding on the reading route's plugin was tried and refused
			// the legitimate case, because a key read through core's payment_gateways
			// belongs to the gateway's plugin, not to core.
			if ( (string) $key !== (string) $entry['field'] ) {
				continue;
			}
			$real = self::read_secret( (string) $entry['ability'], (array) $entry['pointer'] );
			if ( null !== $real ) {
				$value = $real;
			}
		}
	}

	/**
	 * Re-read a secret from the ability it was minted from.
	 *
	 * @param string            $ability Source ability.
	 * @param array<int,string> $pointer Path to the value.
	 * @return string|null
	 */
	private static function read_secret( string $ability, array $pointer ): ?string {
		$source = wp_get_ability( $ability );
		if ( ! $source ) {
			return null;
		}
		self::$resolving = true;
		$data            = $source->execute( array() );
		self::$resolving = false;

		if ( is_wp_error( $data ) || ! is_array( $data ) ) {
			return null;
		}
		$node = $data;
		foreach ( $pointer as $step ) {
			$key = is_numeric( $step ) ? (int) $step : $step;
			if ( is_object( $node ) ) {
				$node = (array) $node;
			}
			if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
				return null;
			}
			$node = $node[ $key ];
		}
		return is_string( $node ) ? $node : null;
	}

	/**
	 * The reference map for the current user, with expired entries dropped.
	 *
	 * @return array<string,mixed>
	 */
	private static function secret_map(): array {
		$map = get_transient( WC_Agent_Tools_Config::SECRET_MAP_KEY . '_' . get_current_user_id() );
		if ( ! is_array( $map ) ) {
			return array();
		}
		$now = time();
		foreach ( $map as $handle => $entry ) {
			if ( ! is_array( $entry ) || ( $entry['expires'] ?? 0 ) < $now ) {
				unset( $map[ $handle ] );
			}
		}
		return $map;
	}

	/**
	 * Persist the reference map.
	 *
	 * @param array<string,mixed> $map Reference map.
	 */
	private static function store_secret_map( array $map ): void {
		set_transient( WC_Agent_Tools_Config::SECRET_MAP_KEY . '_' . get_current_user_id(), $map, WC_Agent_Tools_Config::SECRET_TTL );
	}
}
