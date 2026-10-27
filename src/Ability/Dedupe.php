<?php
/**
 * Signatures of hand-written abilities, so a derived duplicate yields to one an author wrote.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

namespace WooCommerce\AgentTools\Ability;

use WooCommerce\AgentTools\Config;
use WooCommerce\AgentTools\Route\Naming;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signatures of hand-written abilities, so a derived duplicate yields to one an author wrote.
 */
final class Dedupe {

	/**
	 * Ability naming.
	 *
	 * @var Naming
	 */
	private $naming;

	/**
	 * Wire the collaborators this class calls.
	 *
	 * @param Naming $naming Ability naming.
	 */
	public function __construct( Naming $naming ) {
		$this->naming = $naming;
	}

	/**
	 * Signatures of abilities already declared by hand, scoped by owning namespace.
	 *
	 * Each signature carries the ability's name and how many parameters it publishes. The
	 * count is what the compiler compares against, because a hand-written ability is not
	 * automatically the better tool: WooCommerce's curated `products-query` publishes 8
	 * parameters where the route behind the derived `products-list` declares 45, so yielding
	 * to it silently removed filtering by category, tax class, brand, price and sale status.
	 *
	 * @return array<string,array{name:string,params:int}>
	 */
	public function declared_signatures(): array {
		$out = array();
		foreach ( wp_get_abilities() as $ability ) {
			$name = $ability->get_name();
			if ( false === strpos( $name, '/' ) ) {
				continue;
			}
			// A derived ability must never dedupe against anything this plugin registered,
			// derived or hand-written: the discovery tools were counting as foreign and
			// suppressing derived abilities of our own.
			if ( in_array(
				$ability->get_category(),
				array( Config::CATEGORY, Config::DISCOVERY_CATEGORY, Config::SURFACE_CATEGORY ),
				true
			) ) {
				continue;
			}
			list( $ns, $slug ) = explode( '/', $name, 2 );
			$split             = preg_split( '/[-_]/', $slug );
			$words             = is_array( $split ) ? $split : array();
			$ops               = array();
			$res               = array();
			foreach ( $words as $w ) {
				if ( '' === $this->naming->op_class( $w ) ) {
					$res[] = $w;
				} else {
					$ops[] = $this->naming->op_class( $w );
				}
			}
			$op          = $ops ? $ops[0] : 'read';
			$sig         = $ns . '|' . $this->naming->resource_key( implode( '-', $res ) ) . '|' . $op;
			$schema      = (array) $ability->get_input_schema();
			$out[ $sig ] = array(
				'name'   => $name,
				'params' => count( (array) ( $schema['properties'] ?? array() ) ),
			);
		}
		return $out;
	}
}
