<?php
/**
 * Payload shapes for routes whose owning plugin has not declared them.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

namespace WooCommerce\AgentTools\Route;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Payload shapes for routes whose owning plugin has not declared them.
 *
 * Reading a handler recovers parameter names and nothing else, and a tool that names a
 * parameter while admitting it cannot describe it is an invitation to go and look: an agent
 * asked to buy a label read twelve source files to find that `origin` wants a single composed
 * `address` rather than `address_1`, and that a package's `id` is the shipment id. It was
 * right to want the contract and wrong about where to get it.
 *
 * So these are bundled, through the same public filter a plugin author would use. They are not
 * special-cased inside the compiler and they carry no privileges: anything here could be
 * deleted and the only loss would be description. The right home for them is
 * woocommerce-shipping itself, and this class is the argument for moving them there.
 *
 * Shapes verified against `client/utils/location/api-request.ts` (`mapAddressForRequest`) and
 * `client/types/connect-server/request-package.d.ts` (`LabelRequestPackages`).
 */
final class KnownShapes {

	/**
	 * Hook up.
	 */
	public function init(): void {
		add_filter( 'woocommerce_agent_tools_route_args', array( $this, 'declare_args' ), 10, 3 );
	}

	/**
	 * Add declarations for routes we know the shape of.
	 *
	 * @param mixed  $args    The route's declared args, as whatever an earlier callback returned.
	 * @param string $route   Route pattern.
	 * @param mixed  $methods The methods this handler serves.
	 * @return mixed
	 */
	public function declare_args( $args, $route, $methods = array() ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}

		// A route string is shared by every handler on it. `/label/purchase/{order_id}`
		// answers GET with the labels an order already has and POST by buying one, so
		// declaring the purchase payload for both made the read demand a rate and a parcel.
		$serves_write = false;
		foreach ( (array) $methods as $method => $enabled ) {
			if ( $enabled && ! in_array( strtoupper( (string) $method ), array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
				$serves_write = true;
				break;
			}
		}
		if ( ! $serves_write ) {
			return $args;
		}

		$is_purchase = false !== strpos( (string) $route, '/wcshipping/v1/label/purchase' );
		$is_rate     = false !== strpos( (string) $route, '/wcshipping/v1/label/rate' );

		if ( ! $is_purchase && ! $is_rate ) {
			return $args;
		}

		$address = array(
			'type'        => 'object',
			'required'    => true,
			'description' => 'A single composed street line in `address`, not `address_1`/`address_2`.',
			'properties'  => array(
				'name'     => array( 'type' => 'string' ),
				'company'  => array( 'type' => 'string' ),
				'phone'    => array(
					'type'        => 'string',
					'required'    => true,
					// The connect server rejects an empty phone with `"origin.phone" is not
					// allowed to be empty`, and it is the only field it insists on beyond the
					// address itself. Declared optional, it was omitted and every rate lookup
					// failed.
					'description' => 'Required and non-empty: the carrier API rejects a blank phone.',
				),
				'email'    => array( 'type' => 'string' ),
				'address'  => array(
					'type'        => 'string',
					'required'    => true,
					'description' => 'Street address, with any second line folded in.',
				),
				'city'     => array(
					'type'     => 'string',
					'required' => true,
				),
				'state'    => array( 'type' => 'string' ),
				'postcode' => array(
					'type'     => 'string',
					'required' => true,
				),
				'country'  => array(
					'type'     => 'string',
					'required' => true,
				),
			),
		);

		$args['origin']      = $address;
		$args['destination'] = $address;

		$args['packages'] = array(
			'type'        => 'array',
			'required'    => true,
			'description' => 'One entry per parcel. `id` is the shipment id, which is "0" for a single-shipment order. A parcel needs `box_id` and all three dimensions as well as `weight`; the route rejects any subset of those.',
			'items'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'           => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'The shipment id, typically "0".',
					),
					'box_id'       => array(
						'type'        => 'string',
						'required'    => true,
						'description' => 'A saved box id from the store\'s packaging list.',
					),
					'weight'       => array(
						'type'        => 'number',
						'required'    => true,
						'description' => 'Total weight in the store\'s weight unit, parcel included. Products carry no weight unless the merchant set one, so ask rather than inventing a figure: it changes the price.',
					),
					'length'       => array(
						'type'     => 'number',
						'required' => true,
					),
					'width'        => array(
						'type'     => 'number',
						'required' => true,
					),
					'height'       => array(
						'type'     => 'number',
						'required' => true,
					),
					'is_letter'    => array( 'type' => 'boolean' ),
					'carrier_id'   => array( 'type' => 'string' ),
					'service_id'   => array( 'type' => 'string' ),
					'service_name' => array( 'type' => 'string' ),
					'rate_id'      => array( 'type' => 'string' ),
					'shipment_id'  => array( 'type' => 'string' ),
					'products'     => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				),
			),
		);

		if ( ! $is_purchase ) {
			return $args;
		}

		$args['selected_rate'] = array(
			'type'        => 'object',
			'required'    => true,
			'description' => 'Keyed by shipment id, each holding the rate chosen from label-rate-create.',
		);

		foreach ( array( 'hazmat', 'customs' ) as $field ) {
			$args[ $field ] = array(
				'type'        => 'object',
				'required'    => true,
				'description' => 'Required even when empty, and keyed by shipment id: the handler reads the first key, so an empty array will fail.',
			);
		}

		return $args;
	}
}
