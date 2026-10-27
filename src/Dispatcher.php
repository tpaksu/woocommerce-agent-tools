<?php
/**
 * Executes a derived ability by dispatching its route through the REST server, so the route's own validation and permission callback run unchanged.
 *
 * @package woocommerce-agent-tools
 */

declare( strict_types=1 );

namespace WooCommerce\AgentTools;

use WooCommerce\AgentTools\Safety\Confirm;
use WooCommerce\AgentTools\Safety\Secrets;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Executes a derived ability by dispatching its route through the REST server, so the route's own validation and permission callback run unchanged.
 */
final class Dispatcher {

	/**
	 * Credential redaction.
	 *
	 * @var Secrets
	 */
	private $secrets;

	/**
	 * Wire the collaborators this class calls.
	 *
	 * @param Secrets $secrets Credential redaction.
	 */
	public function __construct( Secrets $secrets ) {
		$this->secrets = $secrets;
	}

	/**
	 * Whether a dispatch from this class is in flight.
	 *
	 * `rest_do_request()` fires `rest_pre_dispatch`, so the guard that holds direct REST
	 * writes also saw calls arriving through an ability. Those are gated one layer up, and
	 * the guard's token is keyed on the route while the ability's is keyed on the ability,
	 * so an agent alternated between two tokens forever with one field to put them in.
	 *
	 * Counted rather than a flag: a dispatched route can execute another ability, and the
	 * inner call's reset would otherwise clear the flag while the outer one is still running.
	 *
	 * @var int
	 */
	private $depth = 0;

	/**
	 * Whether the current REST request came from an ability rather than a client.
	 */
	public function is_internal(): bool {
		return $this->depth > 0;
	}

	/**
	 * The generic execute wrapper: build a request and dispatch it through the REST API,
	 * so the route's own validation and permission callback run unchanged.
	 *
	 * @param string              $route   Route pattern.
	 * @param string              $method  HTTP method.
	 * @param array<string,mixed> $input   Ability input.
	 * @param string              $owner   Owning plugin slug, for secret-reference binding.
	 * @param string              $ability Ability name, recorded when minting references.
	 * @param array<string,mixed> $schema  This tool's published properties, for explaining a rejection.
	 * @return mixed
	 */
	public function dispatch( string $route, string $method, array $input, string $owner = '', string $ability = '', array $schema = array() ) {
		// A readonly ability is never gated, so nothing else removes the token, and it
		// reached WooCommerce Shipping as an unknown field: "confirm is not allowed".
		unset( $input[ Confirm::FIELD ] );
		$input                               = $this->secrets->resolve_secrets( $input, $owner );
		list( $path, $rest, $url, $missing ) = $this->split_input( $route, $input );

		if ( ! empty( $missing ) ) {
			return new WP_Error(
				'wcat_missing_path_parameter',
				sprintf(
					'This tool needs %s to address the resource. Supply it and call again.',
					implode(
						' and ',
						array_map(
							function ( $name ) {
								return '`' . $name . '`'; },
							$missing
						)
					)
				),
				array( 'status' => 400 )
			);
		}

		$request = new WP_REST_Request( $method, $path );
		$request->set_url_params( $url );
		if ( 'GET' === $method ) {
			foreach ( $rest as $k => $v ) {
				$request->set_param( $k, $v );
			}
		} else {
			// A JSON body reaches get_json_params() readers and, through the parameter
			// order, get_param() readers too. set_param() alone reaches only the latter.
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $rest ) );
		}

		// rest_do_request() converts a WP_Error into a response, so there is no error
		// return to guard against here; the status is what carries the failure.
		++$this->depth;
		try {
			$response = rest_do_request( $request );
		} finally {
			--$this->depth;
		}
		if ( $response->is_error() ) {
			return $this->explain( $response->as_error(), $response->get_status(), $schema );
		}
		$data = $response->get_data();
		$data = is_array( $data ) ? $data : array( 'value' => $data );
		return $this->secrets->redact_secrets( $data, $ability, $owner );
	}

	/**
	 * Resolve the route and invoke its real permission callback up front, as the current user.
	 *
	 * @param string              $route  Route pattern.
	 * @param string              $method HTTP method.
	 * @param array<string,mixed> $input  Ability input.
	 * @return bool
	 */
	public function check( string $route, string $method, array $input ): bool {
		$handlers = rest_get_server()->get_routes();
		if ( ! isset( $handlers[ $route ] ) ) {
			return false;
		}
		list( $path, $rest, $url, $missing ) = $this->split_input( $route, $input );

		foreach ( $handlers[ $route ] as $handler ) {
			if ( ! is_array( $handler ) || empty( $handler['methods'][ $method ] ) ) {
				continue;
			}
			$cb = $handler['permission_callback'] ?? null;
			if ( ! is_callable( $cb ) ) {
				return false;
			}
			$request = new WP_REST_Request( $method, $path );
			$request->set_url_params( $url );
			if ( 'GET' === $method ) {
				foreach ( $rest as $k => $v ) {
					$request->set_param( $k, $v );
				}
			} else {
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( (string) wp_json_encode( $rest ) );
			}
			$result = call_user_func( $cb, $request );
			return ! is_wp_error( $result ) && false !== $result;
		}
		return false;
	}

	/**
	 * Turn a route's rejection into something a caller can act on.
	 *
	 * The route's own message names the field and stops: "packages[0] does not match any of
	 * the allowed types" says nothing about what those types are, and "X is a required
	 * property" nothing about X's shape. An agent that cannot learn the shape from the tool
	 * goes looking for it in the source, so answer the question here instead: repeat the
	 * route's message, then the schema for each field it complained about, then what this
	 * tool accepts.
	 *
	 * @param WP_Error            $error  The route's error.
	 * @param int                 $status HTTP status.
	 * @param array<string,mixed> $schema This tool's published properties.
	 * @return WP_Error
	 */
	private function explain( WP_Error $error, int $status, array $schema ): WP_Error {
		$message = $error->get_error_message();
		$data    = (array) $error->get_error_data();
		$fields  = array();

		// rest_invalid_param and rest_missing_callback_param both name the offenders.
		foreach ( array( 'params', 'details' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$fields = array_merge( $fields, array_keys( $data[ $key ] ) );
			}
		}
		foreach ( array_keys( $schema ) as $name ) {
			if ( false !== strpos( $message, (string) $name ) ) {
				$fields[] = (string) $name;
			}
		}

		$expected = array();
		foreach ( array_unique( $fields ) as $field ) {
			$root = (string) preg_replace( '/\[.*$/', '', (string) $field );
			if ( isset( $schema[ $root ] ) && ! isset( $expected[ $root ] ) ) {
				$expected[ $root ] = $schema[ $root ];
			}
		}

		if ( ! empty( $expected ) ) {
			$json     = (string) wp_json_encode( $expected );
			$message .= ' Expected shape: ' . ( strlen( $json ) > 1500 ? substr( $json, 0, 1500 ) . '…(truncated)' : $json );
		}
		if ( ! empty( $schema ) ) {
			$message .= ' This tool accepts: ' . implode( ', ', array_keys( $schema ) ) . '.';
		}

		return new WP_Error(
			'wcat_rest_error',
			$message,
			array(
				'status'   => $status,
				'expected' => $expected,
			)
		);
	}

	/**
	 * Split ability input into a concrete path, remaining params, and url params.
	 *
	 * @param string              $route Route pattern.
	 * @param array<string,mixed> $input Ability input.
	 * @return array{0:string,1:array<string,mixed>,2:array<string,mixed>,3:string[]}
	 */
	private function split_input( string $route, array $input ): array {
		$path    = $route;
		$url     = array();
		$missing = array();
		if ( preg_match_all( '#\(\?P<([^>]+)>[^)]*\)#', $route, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$key   = $m[1];
				$value = $input[ $key ] ?? ( $input[ strtolower( $key ) ] ?? null );
				if ( null === $value || '' === $value ) {
					$missing[] = $key;
				}
				$path        = str_replace( $m[0], rawurlencode( (string) $value ), $path );
				$url[ $key ] = $value;
				unset( $input[ $key ], $input[ strtolower( $key ) ] );
			}
		}
		$path = str_replace( '\\/', '/', $path );
		return array( $path, $input, $url, $missing );
	}
}
