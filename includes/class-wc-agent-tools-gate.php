<?php
/**
 * Applies the confirmation gate to every ability on the site, not just derived ones.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies the confirmation gate to every ability on the site, not just derived ones.
 *
 * Gating inside the abilities this plugin builds made confirmation a property of the factory
 * rather than of the store. WooCommerce registers curated abilities of its own, three of them
 * flagged destructive, and none passed through that gate: `product-delete` destroyed a product
 * on a first call, and `order-update-status` set an order to `refunded`, which fires
 * `wc_order_fully_refunded()` and creates a real refund record. The agent reported the opposite
 * to the merchant in good faith, because nothing offered it a preview to read.
 *
 * `wp_pre_execute_ability` runs for every registered ability before input normalization,
 * validation and permission checks, and short-circuits cleanly. That makes the rule a property
 * of the site. One gate and one token also means it cannot deadlock against itself.
 *
 * Curated schemas declare `additionalProperties: false`, so a token cannot be published on them
 * as a property. It does not need to be: this filter reads the raw input before validation, and
 * `wp_ability_normalize_input` runs afterwards and removes the field before the schema sees it.
 */
final class WC_Agent_Tools_Gate {

	/**
	 * Hook up.
	 */
	public static function init(): void {
		add_filter( 'wp_pre_execute_ability', array( __CLASS__, 'hold' ), 10, 3 );
		add_filter( 'wp_ability_normalize_input', array( __CLASS__, 'strip_token' ), 10, 2 );
	}

	/**
	 * Hold an unconfirmed write, or let a confirmed one through.
	 *
	 * @param mixed  $pre     Sentinel while no callback has short-circuited.
	 * @param string $ability Ability name.
	 * @param mixed  $input   Raw input, before normalization.
	 * @return mixed
	 */
	public static function hold( $pre, $ability, $input ) {
		// Another callback already answered for this invocation; do not overwrite it.
		if ( ! $pre instanceof WP_Filter_Sentinel ) {
			return $pre;
		}

		if ( ! self::applies( (string) $ability ) ) {
			return $pre;
		}

		$allowed = WC_Agent_Tools_Confirm::gate(
			(string) $ability,
			self::describe_action( (string) $ability ),
			(array) $input
		);

		return is_wp_error( $allowed ) ? $allowed : $pre;
	}

	/**
	 * Remove the token before schema validation rejects it as an unknown property.
	 *
	 * @param mixed  $input   Normalized input.
	 * @param string $ability Ability name.
	 * @return mixed
	 */
	public static function strip_token( $input, $ability ) {
		if ( is_array( $input ) && self::applies( (string) $ability ) ) {
			unset( $input[ WC_Agent_Tools_Confirm::FIELD ] );
		}
		return $input;
	}

	/**
	 * Whether this ability runs behind the gate.
	 *
	 * Holds agent traffic only; see `Rest_Guard::is_agent_context()` for why. Within that,
	 * read-only is the only exemption that can be read off an annotation. Everything else is
	 * held, including an ability that annotated nothing: silence is not a promise of safety.
	 *
	 * @param string $ability Ability name.
	 */
	public static function applies( string $ability ): bool {
		$namespace = strtok( $ability, '/' );
		if ( in_array( (string) $namespace, WC_Agent_Tools_Config::GATE_EXEMPT_NAMESPACES, true ) ) {
			return false;
		}

		$instance = wp_get_ability( $ability );
		if ( ! $instance instanceof WP_Ability ) {
			return false;
		}

		$annotations = (array) ( $instance->get_meta()['annotations'] ?? array() );
		$gated       = WC_Agent_Tools_Rest_Guard::is_agent_context()
			&& true !== ( $annotations['readonly'] ?? null );

		/**
		 * Filters whether an ability runs behind the confirmation gate.
		 *
		 * @since 0.4.0
		 *
		 * @param bool       $gated    Whether confirmation is required.
		 * @param string     $ability  Ability name.
		 * @param WP_Ability $instance The ability.
		 */
		return (bool) apply_filters( 'woocommerce_agent_tools_ability_needs_confirmation', $gated, $ability, $instance );
	}

	/**
	 * What the preview says this call would do.
	 *
	 * @param string $ability Ability name.
	 */
	private static function describe_action( string $ability ): string {
		$instance = wp_get_ability( $ability );
		if ( ! $instance instanceof WP_Ability ) {
			return $ability;
		}
		$label = trim( (string) $instance->get_label() );
		return '' !== $label ? $label : $ability;
	}
}
