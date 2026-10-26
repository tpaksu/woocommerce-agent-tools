<?php
/**
 * Makes a high-stakes call declare itself before it happens.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes a high-stakes call declare itself before it happens.
 *
 * Telling an agent to confirm before spending money does not work. It reads the sentence,
 * agrees with it, and then decides that this particular call is the diligent exception. The
 * only instruction it cannot reason past is one it is unable to carry out, so a call that
 * spends money or destroys something is split in two: the first attempt returns a preview of
 * exactly what would happen and a token bound to that payload, and nothing happens until the
 * same payload comes back with the token.
 *
 * This forces the call to be stated before it is made. It cannot force a human to read the
 * statement: an agent is free to send the token straight back. What it buys is that the
 * intent is on the record, in the agent's own words, before the money moves, and that a
 * client or a merchant reading along has a place to intervene.
 */
final class WC_Agent_Tools_Confirm {

	const TTL    = 300;
	const PREFIX = 'wcat_confirm_';
	const FIELD  = 'confirm';

	/**
	 * Whether this call needs confirming.
	 *
	 * Every write, and no read. A curated list of money-spending routes was tried first and
	 * failed twice in one test: it gated ten read-only tools, because it matched the route
	 * and ignored the method, and it missed `/label/refund/` because the pattern said
	 * `/refunds`. A real money reversal went to the carrier unannounced as a result. Since
	 * the design already concedes it cannot tell one write from another — `destructive` is
	 * true for all of them — the honest rule is that none of them runs unannounced.
	 *
	 * @param string $route    Route pattern.
	 * @param string $method   HTTP method.
	 * @param bool   $is_read  Whether the compiler judged this call read-only.
	 */
	public static function required( string $route, string $method, bool $is_read ): bool {
		/**
		 * Filters whether a call must be confirmed before it runs.
		 *
		 * @since 0.4.0
		 *
		 * @param bool   $needed   Whether confirmation is required.
		 * @param string $route    Route pattern.
		 * @param string $method   HTTP method.
		 * @param bool   $is_read  Whether the call is read-only.
		 */
		return (bool) apply_filters( 'woocommerce_agent_tools_route_needs_confirmation', ! $is_read, $route, $method, $is_read );
	}

	/**
	 * Check a call against its token, or refuse it with a preview.
	 *
	 * @param string              $ability Ability name.
	 * @param string              $what    What the call would do, in words the merchant reads.
	 * @param array<string,mixed> $input   Ability input, token included.
	 * @return true|WP_Error True when the call may proceed.
	 */
	public static function gate( string $ability, string $what, array $input ) {
		$supplied = isset( $input[ self::FIELD ] ) ? (string) $input[ self::FIELD ] : '';
		unset( $input[ self::FIELD ] );

		$expected = self::token( $ability, $input );

		if ( '' !== $supplied && hash_equals( $expected, $supplied ) && self::is_live( $expected ) ) {
			self::spend( $expected );
			return true;
		}

		self::remember( $expected );

		return new WP_Error(
			'wcat_confirmation_required',
			sprintf(
				'%s is not reversible, so it did not run. This is what it would do: %s with %s. '
					. 'Put that in front of the merchant in those terms, including anything you inferred or '
					. 'chose yourself, and wait for their answer. If they agree, call again with the same '
					. 'arguments plus confirm="%s". If anything above is not what they asked for, do not call '
					. 'again: tell them what does not match.',
				$ability,
				$what,
				self::describe( $input ),
				$expected
			),
			array(
				'status'              => 428,
				'confirm'             => $expected,
				'expires_in'          => self::TTL,
				'arguments_previewed' => $input,
			)
		);
	}

	/**
	 * The confirm property to publish on a gated tool.
	 *
	 * @return array<string,mixed>
	 */
	public static function property(): array {
		return array(
			'type'        => 'string',
			'description' => 'Required, and only obtainable by calling once without it: that first call runs nothing and returns a preview to show the merchant, plus the token to send back here with identical arguments.',
		);
	}

	/**
	 * A token bound to this ability, this user and these exact arguments, so confirming one
	 * call cannot authorise a different one.
	 *
	 * @param string              $ability Ability name.
	 * @param array<string,mixed> $input   Input without the token.
	 */
	private static function token( string $ability, array $input ): string {
		self::sort_deep( $input );
		return substr(
			hash_hmac( 'sha256', $ability . '|' . get_current_user_id() . '|' . (string) wp_json_encode( $input ), wp_salt( 'nonce' ) ),
			0,
			32
		);
	}

	/**
	 * Human-readable arguments for the preview.
	 *
	 * @param array<string,mixed> $input Input without the token.
	 */
	private static function describe( array $input ): string {
		if ( empty( $input ) ) {
			return 'no arguments';
		}
		$parts = array();
		foreach ( $input as $key => $value ) {
			if ( is_bool( $value ) ) {
				// (string) false is the empty string, which dropped the most consequential field
				// in the payload from the preview: `force=` and `api_refund=` read as absent.
				$rendered = $value ? 'true' : 'false';
			} elseif ( null === $value ) {
				$rendered = 'null';
			} elseif ( is_scalar( $value ) ) {
				$rendered = (string) $value;
			} else {
				$rendered = (string) wp_json_encode( $value );
			}
			if ( strlen( $rendered ) > 200 ) {
				$rendered = substr( $rendered, 0, 200 ) . '…';
			}
			$parts[] = $key . '=' . $rendered;
		}
		return implode( ', ', $parts );
	}

	/**
	 * Sort an array by key at every level, so argument order cannot change the token.
	 *
	 * @param array<string,mixed> $value Array to sort, by reference.
	 */
	private static function sort_deep( array &$value ): void {
		ksort( $value );
		foreach ( $value as &$item ) {
			if ( is_array( $item ) ) {
				self::sort_deep( $item );
			}
		}
	}

	/**
	 * Record a token as issued.
	 *
	 * @param string $token Token.
	 */
	private static function remember( string $token ): void {
		set_transient( self::PREFIX . $token, 1, self::TTL );
	}

	/**
	 * Whether a token was issued and has not been used.
	 *
	 * @param string $token Token.
	 */
	private static function is_live( string $token ): bool {
		return false !== get_transient( self::PREFIX . $token );
	}

	/**
	 * Burn a token, so one confirmation authorises exactly one call.
	 *
	 * @param string $token Token.
	 */
	private static function spend( string $token ): void {
		delete_transient( self::PREFIX . $token );
	}
}
