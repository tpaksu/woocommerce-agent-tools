<?php
/**
 * Applies the confirmation gate to every ability on the site, not just derived ones.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

namespace WooCommerce\AgentTools\Safety;

use WooCommerce\AgentTools\Config;
use WP_Ability;
use WP_Filter_Sentinel;

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
final class Gate {

	/**
	 * The confirmation protocol.
	 *
	 * @var Confirm
	 */
	private $confirm;

	/**
	 * The REST-level confirmation gate.
	 *
	 * @var RestGuard
	 */
	private $rest_guard;

	/**
	 * Wire the collaborators this class calls.
	 *
	 * @param Confirm   $confirm    The confirmation protocol.
	 * @param RestGuard $rest_guard The REST-level confirmation gate.
	 */
	public function __construct(
		Confirm $confirm,
		RestGuard $rest_guard
	) {
		$this->confirm    = $confirm;
		$this->rest_guard = $rest_guard;
	}

	/**
	 * Hook up.
	 */
	public function init(): void {
		add_filter( 'wp_pre_execute_ability', array( $this, 'hold' ), 10, 3 );
		add_filter( 'wp_ability_normalize_input', array( $this, 'strip_token' ), 10, 2 );
		// Both fire after `wp_pre_execute_ability`, so a claim taken by the gate can still be
		// refused with nothing run. Not `wp_ability_execute_result`: by then it has run.
		// As late as a priority can put it, because core advertises both filters for callbacks
		// that flip the value. Not a guarantee of running last: WooCommerce's own
		// AbilitiesTracking hooks both at PHP_INT_MAX from `init`, which is after this, so it
		// runs after us. It returns the value unchanged, so the release still sees the answer.
		add_filter( 'wp_ability_permission_result', array( $this, 'release_on_refusal' ), PHP_INT_MAX, 2 );
		add_filter( 'wp_ability_validate_input', array( $this, 'release_on_invalid' ), PHP_INT_MAX, 3 );
		// Fires only once validation and permission have both passed, so the call is committed
		// and its confirmation must never be handed back.
		add_action( 'wp_before_execute_ability', array( $this, 'commit' ), 10, 1 );
	}

	/**
	 * Hold an unconfirmed write, or let a confirmed one through.
	 *
	 * @param mixed  $pre     Sentinel while no callback has short-circuited.
	 * @param string $ability Ability name.
	 * @param mixed  $input   Raw input, before normalization.
	 * @return mixed
	 */
	public function hold( $pre, $ability, $input ) {
		// Another callback already answered for this invocation; do not overwrite it.
		if ( ! $pre instanceof WP_Filter_Sentinel ) {
			return $pre;
		}

		if ( ! $this->applies( (string) $ability ) ) {
			return $pre;
		}

		$opaque  = $this->is_opaque( (string) $ability );
		$allowed = $this->confirm->gate(
			(string) $ability,
			$opaque ? $this->describe_tool( (string) $ability ) : $this->describe_action( (string) $ability ),
			(array) $input,
			$this->self_serve_url( (string) $ability ),
			$opaque
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
	public function strip_token( $input, $ability ) {
		if ( is_array( $input ) && $this->applies( (string) $ability ) ) {
			unset( $input[ Confirm::FIELD ] );
		}
		return $input;
	}

	/**
	 * Whether this ability runs behind the gate.
	 *
	 * Holds agent traffic only; see `Rest_Guard::is_agent_context()` for why. Within that, a
	 * derived ability carries the decision its own description was built from, because
	 * `woocommerce_agent_tools_route_needs_confirmation` can gate a read or release a write and
	 * a tool that says "see `confirm`" has to be held. For anything else read-only is the only
	 * exemption an annotation can carry, and an ability that annotated nothing is held:
	 * silence is not a promise of safety.
	 *
	 * @param string $ability Ability name.
	 */
	public function applies( string $ability ): bool {
		$namespace = strtok( $ability, '/' );
		if ( in_array( (string) $namespace, Config::GATE_EXEMPT_NAMESPACES, true ) ) {
			return false;
		}

		$instance = wp_get_ability( $ability );
		if ( ! $instance instanceof WP_Ability ) {
			return false;
		}

		$meta        = (array) $instance->get_meta();
		$annotations = (array) ( $meta['annotations'] ?? array() );
		// Only for abilities in this plugin's own category. A bare key on anyone's meta bag is an
		// unsanctioned opt-out from the gate; `woocommerce_agent_tools_ability_needs_confirmation`
		// is the supported one.
		$ours       = Config::CATEGORY === $instance->get_category();
		$wants_gate = ( $ours && array_key_exists( 'wcat_gated', $meta ) )
			? (bool) $meta['wcat_gated']
			: true !== ( $annotations['readonly'] ?? null );
		$gated      = $this->rest_guard->is_agent_context() && $wants_gate;

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
	private function describe_action( string $ability ): string {
		$instance = wp_get_ability( $ability );
		if ( ! $instance instanceof WP_Ability ) {
			return $ability;
		}
		$label = trim( (string) $instance->get_label() );
		return '' !== $label ? $label : $ability;
	}

	/**
	 * Whether this call's route declares no payload to preview.
	 *
	 * @param string $ability Ability name.
	 */
	private function is_opaque( string $ability ): bool {
		$instance = $this->derived( $ability );

		return $instance instanceof WP_Ability && ! empty( $instance->get_meta()['opaque'] );
	}

	/**
	 * The ability behind this name, but only if it is in this plugin's own category.
	 *
	 * `opaque` and `self_serve_url` are read off the meta bag, and a bare key there from
	 * another plugin would let it describe a call with parameters as having none and send the
	 * merchant to a screen of its choosing.
	 *
	 * @param string $ability Ability name.
	 * @return WP_Ability|null
	 */
	private function derived( string $ability ): ?WP_Ability {
		$instance = wp_get_ability( $ability );
		if ( ! $instance instanceof WP_Ability || Config::CATEGORY !== $instance->get_category() ) {
			return null;
		}

		return $instance;
	}

	/**
	 * Stop tracking a claim once the call it belongs to is certain to run.
	 *
	 * @param string $ability Ability name.
	 */
	public function commit( $ability ): void {
		$this->confirm->commit( (string) $ability );
	}

	/**
	 * Hand a confirmation back when the permission check refuses the call.
	 *
	 * @param mixed  $permission Permission result.
	 * @param string $ability    Ability name.
	 * @return mixed
	 */
	public function release_on_refusal( $permission, $ability ) {
		if ( true !== $permission ) {
			$this->confirm->release( (string) $ability );
		}
		return $permission;
	}

	/**
	 * Hand a confirmation back when input validation refuses the call.
	 *
	 * @param mixed  $validity Validation result.
	 * @param mixed  $input    Normalized input.
	 * @param string $ability  Ability name.
	 * @return mixed
	 */
	public function release_on_invalid( $validity, $input, $ability ) {
		// Core's own test, not `true !== $validity`: it coerces anything else to valid and runs
		// the call, so releasing on a callback that simply returned nothing would re-arm a
		// confirmation for a write that is about to happen.
		if ( false === $validity || ( is_wp_error( $validity ) && $validity->has_errors() ) ) {
			$this->confirm->release( (string) $ability );
		}
		return $validity;
	}

	/**
	 * What the tool says it does, for a preview whose route declared no payload. Factory appends
	 * the gate boilerplate after the composed description, which the preview need not repeat.
	 *
	 * @param string $ability Ability name.
	 */
	private function describe_tool( string $ability ): string {
		$instance = wp_get_ability( $ability );
		if ( ! $instance instanceof WP_Ability ) {
			return $ability;
		}
		$text = trim( (string) $instance->get_description() );
		$cut  = strpos( $text, Confirm::GATE_NOTE );
		if ( false !== $cut ) {
			$text = trim( substr( $text, 0, $cut ) );
		}

		return '' !== $text ? $text : $this->describe_action( $ability );
	}

	/**
	 * Where the merchant completes this call themselves, for a call whose payload cannot be
	 * previewed. Empty when none is known or none passed the origin check, in which case the
	 * refusal still says the route declares no payload and names no screen.
	 *
	 * @param string $ability Ability name.
	 */
	private function self_serve_url( string $ability ): string {
		$instance = $this->derived( $ability );
		if ( ! $instance instanceof WP_Ability ) {
			return '';
		}
		$meta = $instance->get_meta();

		if ( empty( $meta['opaque'] ) ) {
			return '';
		}

		// Escaped here as well as where Factory writes it, because this is the boundary the
		// value crosses on its way into a message telling the merchant to visit it.
		$url = esc_url_raw( (string) ( $meta['self_serve_url'] ?? '' ) );
		if ( '' === $url ) {
			return '';
		}

		// The merchant is told to go there, so it has to be this site's own admin. The whole
		// origin is compared, not just the host: another scheme or port on the same name is
		// still somewhere else.
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		// Host-less is this site's own, but only as a path: `mailto:` and `tel:` survive
		// `esc_url_raw()` and parse without a host, and a bare path is not clickable.
		if ( ! isset( $parts['host'] ) ) {
			// The 'admin' scheme, so this cannot disagree with `admin_url()` under
			// FORCE_SSL_ADMIN on a request that did not arrive over SSL.
			return 0 === strpos( $url, '/' ) ? site_url( $url, 'admin' ) : '';
		}

		// On multisite a network screen is still this installation.
		foreach ( array( admin_url(), network_admin_url() ) as $permitted ) {
			$mine = wp_parse_url( $permitted );
			if ( ! is_array( $mine ) ) {
				continue;
			}
			if ( strtolower( (string) $parts['host'] ) === strtolower( (string) ( $mine['host'] ?? '' ) )
				&& strtolower( (string) ( $parts['scheme'] ?? '' ) ) === strtolower( (string) ( $mine['scheme'] ?? '' ) )
				&& $this->port( $parts ) === $this->port( $mine ) ) {
				return $url;
			}
		}

		return '';
	}

	/**
	 * A URL's port, with the scheme's default spelled out so `:80` and nothing compare equal.
	 *
	 * @param array<string,mixed> $parts Parsed URL.
	 */
	private function port( array $parts ): int {
		if ( isset( $parts['port'] ) ) {
			return (int) $parts['port'];
		}

		return 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) ? 443 : 80;
	}
}
