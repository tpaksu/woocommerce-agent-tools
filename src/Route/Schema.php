<?php
/**
 * Converts a route argument list into the JSON Schema an ability publishes.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

namespace WooCommerce\AgentTools\Route;

use WooCommerce\AgentTools\Config;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts a route argument list into the JSON Schema an ability publishes.
 *
 * A REST arg definition is JSON Schema with WordPress's own machinery mixed in, and it nests:
 * an object's `properties`, an array's `items`, and the same again inside those. Copying the
 * top level and passing the rest through verbatim loses in both directions. It ships
 * `sanitize_callback`, `arg_options` and `context` to a model that cannot use them, and it
 * keeps a boolean `required` on a child, which in JSON Schema belongs on the parent as a list.
 * So normalise every level.
 */
final class Schema {

	/**
	 * JSON Schema keywords worth publishing. Anything else in a REST arg is WordPress's.
	 *
	 * @var string[]
	 */
	const KEEP = array(
		'additionalProperties',
		'anyOf',
		'default',
		'description',
		'enum',
		'exclusiveMaximum',
		'exclusiveMinimum',
		'format',
		'items',
		'maxItems',
		'maxLength',
		'maximum',
		'minItems',
		'minLength',
		'minimum',
		'oneOf',
		'pattern',
		'patternProperties',
		'properties',
		'type',
		'uniqueItems',
	);

	/**
	 * Turn a handler's args into an object schema: properties, plus the names the route
	 * itself declared required.
	 *
	 * @param array<string,mixed> $args Handler args.
	 * @return array{properties:array<string,mixed>,required:string[]}
	 */
	public function from_args( array $args ): array {
		$properties = array();
		$required   = array();

		foreach ( $args as $key => $spec ) {
			if ( ! is_array( $spec ) ) {
				continue;
			}
			$name                = (string) $key;
			$properties[ $name ] = $this->normalize( $spec );
			if ( ! empty( $spec['required'] ) ) {
				$required[] = $name;
			}
		}

		return array(
			'properties' => $properties,
			'required'   => $required,
		);
	}

	/**
	 * Add the parameters a handler reads that its route never declared.
	 *
	 * They arrive as names without types, so they are published with the permissive union
	 * and never added to `required`: the names come from reading source, and a false
	 * positive that rejected a working call would be worse than an undocumented one.
	 *
	 * @param array<string,mixed> $properties Properties so far.
	 * @param array<string,bool>  $discovered Discovered name => whether the handler requires it.
	 * @param string[]            $url_params Names already supplied by the route path.
	 * @return array{properties:array<string,mixed>,undeclared:string[]}
	 */
	public function with_discovered( array $properties, array $discovered, array $url_params ): array {
		$undeclared = array();

		$known = array_map( 'strtolower', array_merge( array_keys( $properties ), $url_params ) );

		foreach ( $discovered as $name => $handler_requires ) {
			$name = (string) $name;
			if ( in_array( strtolower( $name ), $known, true ) ) {
				continue;
			}
			$properties[ $name ] = array(
				'type'        => Config::ANY_TYPE,
				'description' => $handler_requires
					? 'Required by the handler. This route does not declare it, so its type and shape are unknown here.'
					: 'Read by the handler. This route does not declare it, so its type and shape are unknown here.',
			);
			$undeclared[]        = $name;
		}

		return array(
			'properties' => $properties,
			'undeclared' => $undeclared,
		);
	}

	/**
	 * Publish the route's path placeholders.
	 *
	 * They are structurally required: without them the dispatcher cannot even build a URL.
	 * Leaving them out produced a tool that advertised no parameters at all and answered
	 * "No route was found matching the URL and request method" when called, which reads as
	 * a broken tool rather than a missing argument.
	 *
	 * @param array<string,mixed>  $properties Properties so far.
	 * @param string[]             $required   Required names so far.
	 * @param array<string,string> $specs      Placeholder name => its pattern.
	 * @return array{properties:array<string,mixed>,required:string[]}
	 */
	public function with_path_params( array $properties, array $required, array $specs ): array {
		foreach ( $specs as $name => $pattern ) {
			if ( ! isset( $properties[ $name ] ) ) {
				$properties[ $name ] = array(
					// A digits-only pattern is the overwhelmingly common case and worth typing.
					'type'        => preg_match( '/^\\\\d\+$|^\[\\\\d\]\+$/', $pattern ) ? 'integer' : 'string',
					'description' => 'Identifies the resource in the route path.',
				);
			}
			if ( ! in_array( $name, $required, true ) ) {
				$required[] = $name;
			}
		}
		return array(
			'properties' => $properties,
			'required'   => $required,
		);
	}

	/**
	 * Normalise one schema node, and everything nested inside it.
	 *
	 * @param array<string,mixed> $spec A REST arg or a nested schema.
	 * @return array<string,mixed>
	 */
	public function normalize( array $spec ): array {
		$out = array_intersect_key( $spec, array_flip( self::KEEP ) );

		// An undeclared type is not a string. Saying so rejected every structured value a
		// route was willing to accept. A `type` that is not a name, or a list of names, is
		// not a type either: Bookings declares one as a whole nested schema.
		$out['type'] = $this->type_or_any( $out['type'] ?? null );

		if ( isset( $out['properties'] ) && is_array( $out['properties'] ) ) {
			$nested            = $this->from_args( $out['properties'] );
			$out['properties'] = $nested['properties'];
			if ( ! empty( $nested['required'] ) ) {
				$out['required'] = $nested['required'];
			}
		}

		if ( isset( $out['items'] ) && is_array( $out['items'] ) ) {
			$out['items'] = $this->normalize( $out['items'] );
		}

		foreach ( array( 'oneOf', 'anyOf' ) as $branch ) {
			if ( ! isset( $out[ $branch ] ) || ! is_array( $out[ $branch ] ) ) {
				continue;
			}
			$out[ $branch ] = array_values(
				array_map(
					function ( $alternative ) {
						return is_array( $alternative ) ? $this->normalize( $alternative ) : $alternative;
					},
					$out[ $branch ]
				)
			);
		}

		if ( isset( $out['patternProperties'] ) && is_array( $out['patternProperties'] ) ) {
			$out['patternProperties'] = $this->from_args( $out['patternProperties'] )['properties'];
		}

		return $out;
	}

	/**
	 * A JSON Schema type: a known name, or a list of them, or the permissive union.
	 *
	 * @param mixed $type Whatever the route put in `type`.
	 * @return string|string[]
	 */
	private function type_or_any( $type ) {
		if ( is_string( $type ) && in_array( $type, Config::ANY_TYPE, true ) ) {
			return $type;
		}
		if ( is_array( $type ) ) {
			$names = array_values(
				array_filter(
					$type,
					function ( $candidate ) {
						return is_string( $candidate ) && in_array( $candidate, Config::ANY_TYPE, true );
					}
				)
			);
			if ( ! empty( $names ) ) {
				return 1 === count( $names ) ? $names[0] : $names;
			}
		}
		return Config::ANY_TYPE;
	}

	/**
	 * Schema title off the route options, if the controller provides one.
	 *
	 * @param array<string,mixed> $options Route options.
	 */
	public function schema_title( array $options ): string {
		if ( ! isset( $options['schema'] ) || ! is_callable( $options['schema'] ) ) {
			return '';
		}
		try {
			$schema = call_user_func( $options['schema'] );
		} catch ( Throwable $e ) {
			return '';
		}
		return is_array( $schema ) && ! empty( $schema['title'] ) ? (string) $schema['title'] : '';
	}
}
