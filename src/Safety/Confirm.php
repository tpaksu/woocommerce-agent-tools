<?php
/**
 * Makes a high-stakes call declare itself before it happens.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

namespace WooCommerce\AgentTools\Safety;

use WP_Error;

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
 *
 * One confirmation authorises one call. A payload that has been confirmed is then refused for
 * twice the token's lifetime, whether the token comes back or the agent starts over, because a
 * client that times out and retries would otherwise refund twice.
 */
final class Confirm {

	const GATE_NOTE    = ' Not reversible; see `confirm`.';
	const TTL          = 300;
	const PREFIX       = 'wcat_confirm_';
	const SPENT_PREFIX = 'wcat_spent_';
	const FIELD        = 'confirm';

	/**
	 * Tokens claimed during this request, by ability, so a call refused before it ran can
	 * give its confirmation back.
	 *
	 * @var array<string,string>
	 */
	private $claimed = array();

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
	public function required( string $route, string $method, bool $is_read ): bool {
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
	 * @param string              $ability        Ability name.
	 * @param string              $what           What the call would do, in words the merchant reads.
	 *                                            For an opaque call this is the tool's own
	 *                                            description rather than its label.
	 * @param array<string,mixed> $input          Ability input, token included.
	 * @param string              $self_serve_url Admin screen to hand the call to, or '' when
	 *                                            none is known or none passed the origin check.
	 * @param bool                $opaque         Whether the route declares no payload to preview.
	 * @return true|WP_Error True when the call may proceed.
	 */
	public function gate( string $ability, string $what, array $input, string $self_serve_url = '', bool $opaque = false ) {
		$supplied = isset( $input[ self::FIELD ] ) ? (string) $input[ self::FIELD ] : '';
		unset( $input[ self::FIELD ] );

		$expected = $this->token( $ability, $input );
		if ( '' === $expected ) {
			return new WP_Error(
				'wcat_unidentifiable_payload',
				'These arguments cannot be identified, so this call cannot be confirmed and did not '
					. 'run. They are nested too deeply or hold bytes that are not valid text. Send a '
					. 'simpler payload, or tell the merchant this tool cannot take what you have.',
				array( 'status' => 400 )
			);
		}

		if ( '' !== $supplied && hash_equals( $expected, $supplied ) ) {
			$claim = $this->claim( $expected );
			if ( 'claimed' === $claim ) {
				$this->claimed[ $ability ] = $expected;
				return true;
			}
			// A loser has written the marker itself, so the read below would answer the same.
			// Kept explicit because the two reasons for refusing are not the same fact.
			if ( 'lost' === $claim ) {
				return $this->already_ran( $ability );
			}
		}

		// Read on every path, not only when a token was sent. The token is a deterministic
		// function of the payload, so an agent that restarts the protocol without one would
		// otherwise be handed the same spent token back under a fresh "it did not run" preview,
		// and a timed-out retry would run a second refund.
		if ( false !== get_transient( self::SPENT_PREFIX . $expected ) ) {
			return $this->already_ran( $ability );
		}

		$this->remember( $expected );

		$data = array(
			'status'              => 428,
			'confirm'             => $expected,
			'expires_in'          => self::TTL,
			'arguments_previewed' => $input,
		);

		if ( $opaque ) {
			// Undeclared parameters still reach the route, and `agents.md` tells an agent to
			// infer them from the error it gets back. Whatever it inferred is what the merchant
			// is actually approving, so it cannot be left out of the preview.
			$sending = empty( $input )
				? ' Nothing is being sent with it.'
				: sprintf(
					' The call is nevertheless sending %s, which are values you inferred rather than'
						. ' ones the route asked for.',
					$this->describe( $input )
				);
			$where   = '' !== $self_serve_url
				? sprintf( 'at %s', $self_serve_url )
				: 'in this store\'s wp-admin';
			if ( '' !== $self_serve_url ) {
				$data['complete_in_admin'] = $self_serve_url;
			}

			return new WP_Error(
				'wcat_confirmation_required',
				sprintf(
					'%s is not reversible, so it did not run. Its route declares no parameters, so nothing '
						. 'here can tell you what payload that route expects.%s What the tool says it does is: '
						. '%s Relay all of that, and say that the route has not stated what it takes. Calls in this '
						. 'class include accepting an agreement, connecting an account and moving money, so the '
						. 'normal answer is for them to do it themselves %s. Say what they need to do there in '
						. 'their words, not this plugin\'s. When they report it done, call '
						. '`woocommerce/refresh-abilities` and discover again: finishing one of these usually '
						. 'adds tools, and the list you are holding was built before they did it. Only if they '
						. 'read all this and still ask you to make the call, send the same arguments plus '
						. 'confirm="%s".',
					$ability,
					$sending,
					rtrim( $what, '.' ) . '.',
					$where,
					$expected
				),
				$data
			);
		}

		return new WP_Error(
			'wcat_confirmation_required',
			sprintf(
				'%s is not reversible, so it did not run. This is what it would do: %s with %s. Put '
					. 'that in front of the merchant in their words rather than these, including anything you '
					. 'inferred or chose yourself: what changes, what it changes, and every amount. Keep the '
					. 'tool name and the field names to yourself unless they ask for them. Then wait for their '
					. 'answer. If they agree, call again with the same arguments plus confirm="%s". If anything '
					. 'above is not what they asked for, do not call again: tell them what does not match.',
				$ability,
				$what,
				$this->describe( $input ),
				$expected
			),
			$data
		);
	}

	/**
	 * Stop tracking a claim, because the call it belongs to is now going to run.
	 *
	 * After this there is nothing for `release()` to give back, which is the point: a
	 * confirmation for a call that has executed must never be re-armed.
	 *
	 * @param string $ability Ability name, or the method and route for a direct REST write.
	 */
	public function commit( string $ability ): void {
		unset( $this->claimed[ $ability ] );
	}

	/**
	 * Give a confirmation back, for a call that was claimed and then refused before it ran.
	 *
	 * The gate runs on `wp_pre_execute_ability`, which fires before input validation and the
	 * permission check, so a claim is not proof of execution. Without this, a merchant whose
	 * role was missing one capability was told their call had already run, and then locked out
	 * of the same payload for ten minutes.
	 *
	 * @param string $ability Ability name.
	 */
	public function release( string $ability ): void {
		if ( ! isset( $this->claimed[ $ability ] ) ) {
			return;
		}
		$token = $this->claimed[ $ability ];
		unset( $this->claimed[ $ability ] );

		delete_transient( self::SPENT_PREFIX . $token );
		$this->remember( $token );
	}

	/**
	 * Refuse a call whose payload has already run under this confirmation.
	 *
	 * @param string $ability Ability name.
	 */
	private function already_ran( string $ability ): WP_Error {
		return new WP_Error(
			'wcat_confirmation_spent',
			sprintf(
				'%s was already confirmed and attempted with exactly these arguments, so this store '
					. 'will not preview or run the same payload again for ten minutes. Do not send the '
					. 'token again and do not start over: it is the same payload either way. Read the store '
					. 'to find out what that attempt actually did, because a confirmation is claimed before '
					. 'the route checks permissions, and tell the merchant what you found. If they want it '
					. 'done again on purpose, wait out those ten minutes, or send a request that differs in '
					. 'some way the merchant actually wants.',
				$ability
			),
			array( 'status' => 409 )
		);
	}

	/**
	 * The confirm property to publish on a gated tool.
	 *
	 * @return array<string,mixed>
	 */
	public function property(): array {
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
	private function token( string $ability, array $input ): string {
		$this->sort_deep( $input );

		$encoded = wp_json_encode( $input );
		if ( ! is_string( $encoded ) ) {
			// Past `json_encode`'s depth limit, or unrepairable. Casting the failure to a
			// string hashed every such payload to one token, so a confirmation for any of
			// them authorised all the others.
			return '';
		}

		return substr(
			hash_hmac( 'sha256', $ability . '|' . get_current_user_id() . '|' . $encoded, wp_salt( 'nonce' ) ),
			0,
			32
		);
	}

	/**
	 * Human-readable arguments for the preview.
	 *
	 * @param array<string,mixed> $input Input without the token.
	 */
	private function describe( array $input ): string {
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
			// By character, not byte: cutting mid-character leaves the message invalid UTF-8,
			// which the transport then either repairs by dropping bytes or refuses outright.
			if ( mb_strlen( $rendered, 'UTF-8' ) > 200 ) {
				$rendered = mb_substr( $rendered, 0, 200, 'UTF-8' ) . '…';
			}
			// `discount_type=percent` reads like code to the merchant this is shown to, and an
			// agent relaying it verbatim passes that on. The value is untouched.
			$parts[] = str_replace( '_', ' ', (string) $key ) . ': ' . $rendered;
		}
		return implode( ', ', $parts );
	}

	/**
	 * Sort an array by key at every level, so argument order cannot change the token.
	 *
	 * @param array<string,mixed> $value Array to sort, by reference.
	 */
	private function sort_deep( array &$value ): void {
		ksort( $value );
		foreach ( $value as &$item ) {
			if ( is_array( $item ) ) {
				$this->sort_deep( $item );
			}
		}
	}

	/**
	 * Record a token as issued.
	 *
	 * @param string $token Token.
	 */
	private function remember( string $token ): void {
		set_transient( self::PREFIX . $token, 1, self::TTL );
	}

	/**
	 * Claim a token, so one confirmation authorises exactly one call.
	 *
	 * The delete is the claim: two concurrent calls carrying one token both pass a liveness
	 * read, so only the delete can pick a winner, and a second shipping-label refund has no
	 * inverse. The read ahead of it enforces the TTL, which `delete_transient()` ignores: on
	 * a site with no persistent object cache an expired row survives until the daily cron and
	 * would otherwise still be claimable.
	 *
	 * The caller needs to tell a lost race from a dead token, because only the first means the
	 * call has already run.
	 *
	 * @param string $token Token.
	 * @return 'claimed'|'lost'|'dead' Claimed it, lost it to another call, or it was expired
	 *                                 or never issued.
	 */
	private function claim( string $token ): string {
		if ( false === get_transient( self::PREFIX . $token ) ) {
			return 'dead';
		}

		// The marker goes down before the delete, and the order is the whole point: with it
		// after, there was a window where the token had stopped being claimable and nothing
		// recorded that it had been claimed, so a caller restarting inside that window re-armed
		// a token it could then redeem. It outlives the token so a later retry is answered.
		set_transient( self::SPENT_PREFIX . $token, 1, self::TTL * 2 );

		if ( ! delete_transient( self::PREFIX . $token ) ) {
			// A race loser's token is already gone. One still sitting there means the delete
			// itself failed, and treating that as spent would lock the payload out for ten
			// minutes over a call that never ran, with no claim recorded to release.
			if ( false !== get_transient( self::PREFIX . $token ) ) {
				delete_transient( self::SPENT_PREFIX . $token );
				return 'dead';
			}
			return 'lost';
		}

		return 'claimed';
	}
}
