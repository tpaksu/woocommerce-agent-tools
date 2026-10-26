<?php
/**
 * Registers one descriptor as a real ability.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers one descriptor as a real ability.
 */
final class WC_Agent_Tools_Ability_Factory {

	/**
	 * Register one descriptor as a real ability.
	 *
	 * @param array<string,mixed> $d Descriptor.
	 */
	public static function register_one( array $d ): void {
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

		$gated = WC_Agent_Tools_Confirm::required( $route, $method, $readonly );

		$properties = (array) $d['args'];
		if ( $gated ) {
			$properties[ WC_Agent_Tools_Confirm::FIELD ] = WC_Agent_Tools_Confirm::property();
			// Deliberately terse. The `confirm` property documents the protocol and the
			// handshake explains it once for the whole server; spelling it out per tool cost
			// 33,600 bytes across 168 descriptions, which is half of what `discover-abilities`
			// returns, and that response had already grown past what a client will accept.
			$description .= ' Not reversible; see `confirm`.';

			if ( empty( $d['args'] ) ) {
				// A write whose route declares nothing and whose handler yielded nothing is a
				// tool an agent can only probe blindly. Say that, rather than let the empty
				// schema read as "takes no arguments".
				$description .= ' This route declares no parameters and none could be recovered,'
					. ' so if it needs a payload this tool cannot describe it.';
			}
		}

		$input_schema = array(
			'type'       => 'object',
			'properties' => $properties,
		);
		if ( ! empty( $d['required'] ) ) {
			$input_schema['required'] = array_values( (array) $d['required'] );
		}

		$args = array(
			'label'               => (string) $d['label'],
			'description'         => $description,
			'category'            => WC_Agent_Tools_Config::CATEGORY,
			'input_schema'        => $input_schema,
			// No output_schema, deliberately: an empty one skips validation.
			// No gate here: WC_Agent_Tools_Gate holds every ability on the site, this plugin's
			// and anyone else's, and strips the token before validation sees it.
			'execute_callback'    => static function ( $input = null ) use ( $route, $method, $owner, $name, $input_schema ) {
				$input = (array) $input;
				unset( $input[ WC_Agent_Tools_Confirm::FIELD ] );
				return WC_Agent_Tools_Dispatcher::dispatch( $route, $method, $input, $owner, $name, (array) $input_schema['properties'] );
			},
			'permission_callback' => static function ( $input = null ) use ( $route, $method ) {
				return WC_Agent_Tools_Dispatcher::check( $route, $method, (array) $input );
			},
			'meta'                => array(
				'public'       => (bool) ( $d['public'] ?? $readonly ),
				'show_in_rest' => false,
				'annotations'  => $readonly ? array( 'readonly' => true ) : array( 'destructive' => true ),
			),
		);

		wp_register_ability( (string) $d['name'], $args );
	}
}
