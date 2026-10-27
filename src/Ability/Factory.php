<?php
/**
 * Registers one descriptor as a real ability.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

namespace WooCommerce\AgentTools\Ability;

use WooCommerce\AgentTools\Config;
use WooCommerce\AgentTools\Dispatcher;
use WooCommerce\AgentTools\Safety\Confirm;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers one descriptor as a real ability.
 */
final class Factory {

	/**
	 * The confirmation protocol.
	 *
	 * @var Confirm
	 */
	private $confirm;

	/**
	 * Route dispatch.
	 *
	 * @var Dispatcher
	 */
	private $dispatcher;

	/**
	 * Wire the collaborators this class calls.
	 *
	 * @param Confirm    $confirm    The confirmation protocol.
	 * @param Dispatcher $dispatcher Route dispatch.
	 */
	public function __construct(
		Confirm $confirm,
		Dispatcher $dispatcher
	) {
		$this->confirm    = $confirm;
		$this->dispatcher = $dispatcher;
	}

	/**
	 * Register one descriptor as a real ability.
	 *
	 * @param array<string,mixed> $d Descriptor.
	 */
	public function register_one( array $d ): void {
		$route    = (string) $d['route'];
		$method   = (string) $d['method'];
		$readonly = (bool) $d['readonly'];
		$owner    = (string) $d['owner'];
		$name     = (string) $d['name'];

		$undeclared  = (array) ( $d['undeclared'] ?? array() );
		$description = (string) $d['description'];
		if ( ! empty( $undeclared ) ) {
			// The names came from reading the handler; their types did not. Say so, because
			// the alternative is a model inferring a payload for a call it cannot preview.
			// Each of these properties carries the explanation in its own description, so
			// name them here and no more.
			$description .= sprintf( ' Shapes the route does not declare: %s.', implode( ', ', $undeclared ) );
		}

		$gated = $this->confirm->required( $route, $method, $readonly );

		$properties = (array) $d['args'];
		$opaque     = false;
		if ( $gated ) {
			$properties[ Confirm::FIELD ] = $this->confirm->property();
			// Deliberately terse. The `confirm` property documents the protocol and the
			// handshake explains it once for the whole server; spelling it out per tool cost
			// 33,600 bytes across 168 descriptions, which is half of what `discover-abilities`
			// returns, and that response had already grown past what a client will accept.
			$description .= Confirm::GATE_NOTE;

			if ( empty( $d['args'] ) ) {
				// A write whose route declares nothing and whose handler yielded nothing is a
				// tool an agent can only probe blindly. Say that, rather than let the empty
				// schema read as "takes no arguments".
				$opaque       = true;
				$description .= ' This route declares no parameters and none could be recovered,'
					. ' so if it needs a payload the route has not stated what it is.';
			}
		}

		$input_schema = array(
			'type'       => 'object',
			'properties' => $properties,
		);
		if ( ! empty( $d['required'] ) ) {
			$input_schema['required'] = array_values( (array) $d['required'] );
		}

		$meta = array(
			'public'       => (bool) ( $d['public'] ?? $readonly ),
			'show_in_rest' => false,
			'annotations'  => $readonly ? array( 'readonly' => true ) : array( 'destructive' => true ),
			// What this tool published. The annotations follow the route's own read/write
			// nature, while `woocommerce_agent_tools_route_needs_confirmation` can decide
			// otherwise, and Gate has to enforce what the description promised.
			'wcat_gated'   => $gated,
		);
		if ( $opaque ) {
			// The route states no payload, so Confirm reads these two and points the merchant at
			// the screen where they complete it themselves.
			$meta['opaque']         = true;
			$meta['self_serve_url'] = $this->self_serve_url( $route, $method, $name );
		}

		$args = array(
			'label'               => (string) $d['label'],
			'description'         => $description,
			'category'            => Config::CATEGORY,
			'input_schema'        => $input_schema,
			// No output_schema, deliberately: an empty one skips validation.
			// No gate here: Gate holds every ability on the site, this plugin's
			// and anyone else's, and strips the token before validation sees it.
			'execute_callback'    => function ( $input = null ) use ( $route, $method, $owner, $name, $input_schema ) {
				$input = (array) $input;
				unset( $input[ Confirm::FIELD ] );
				return $this->dispatcher->dispatch( $route, $method, $input, $owner, $name, (array) $input_schema['properties'] );
			},
			'permission_callback' => function ( $input = null ) use ( $route, $method ) {
				return $this->dispatcher->check( $route, $method, (array) $input );
			},
			'meta'                => $meta,
		);

		wp_register_ability( (string) $d['name'], $args );
	}

	/**
	 * Where a merchant completes a call themselves, when its route declares no payload.
	 *
	 * Defaults to the admin root, which is always right and never specific. The plugin that
	 * owns the route is the only thing that knows its screen, so it gets to say; returning an
	 * empty string removes the link; the route still declares no payload, so the refusal keeps
	 * saying so and sends the merchant to wp-admin without naming a screen.
	 *
	 * @param string $route  Route pattern.
	 * @param string $method HTTP method.
	 * @param string $name   Ability name.
	 */
	private function self_serve_url( string $route, string $method, string $name ): string {
		/**
		 * Filters the admin screen a merchant is sent to for a call whose payload cannot be
		 * previewed.
		 *
		 * @since 0.5.0
		 *
		 * The resolved URL is stored in the ability's meta, which `get-ability-info` returns
		 * in full, so it must not carry a nonce or any other one-time credential.
		 *
		 * @param string $url    Admin URL, or '' to leave the call with the ordinary preview.
		 * @param string $route  Route pattern.
		 * @param string $method HTTP method.
		 * @param string $name   Ability name.
		 */
		$url = (string) apply_filters( 'woocommerce_agent_tools_self_serve_url', admin_url(), $route, $method, $name );

		return '' === $url ? '' : esc_url_raw( $url );
	}
}
