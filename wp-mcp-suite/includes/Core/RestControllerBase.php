<?php
/**
 * Abstract base for every module's REST controller.
 *
 * Negative requirement (spec §"Security"): "Nonce and capability checks for
 * all actions." Rather than trust each module to remember this, every route
 * registered through this base class is forced through permission_callback()
 * below. A module cannot register a route with permission_callback set to
 * '__return_true' through this class — register_route() does not expose
 * that parameter.
 *
 * @package MCPSuite\Core
 */

declare( strict_types = 1 );

namespace MCPSuite\Core;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class RestControllerBase {

	protected const NAMESPACE_V1 = 'mcp-suite/v1';

	/**
	 * The capability required to call any route on this controller.
	 * Concrete controllers set this to one of the Capabilities::* constants.
	 */
	abstract protected function required_capability(): string;

	/**
	 * @return array{route:string,methods:string,handler:string,args?:array<string,mixed>}[]
	 */
	abstract protected function routes(): array;

	final public function register_routes(): void {
		foreach ( $this->routes() as $definition ) {
			register_rest_route(
				self::NAMESPACE_V1,
				$definition['route'],
				array(
					'methods'             => $definition['methods'],
					'callback'            => array( $this, $definition['handler'] ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $definition['args'] ?? array(),
				)
			);
		}
	}

	/**
	 * Every route on every module goes through this single check:
	 *   1. Verify the WordPress REST nonce (X-WP-Nonce header).
	 *   2. Verify the caller holds the module's required capability.
	 * Both failures are audit-logged as permission_denied, since a spike in
	 * these is itself a security signal worth having a durable record of.
	 */
	final public function permission_callback( WP_REST_Request $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			AuditLogger::log(
				AuditLogger::ACTION_PERMISSION_DENIED,
				static::class,
				'rest_route',
				null,
				false,
				array( 'reason' => 'invalid_nonce', 'route' => $request->get_route() )
			);

			return new WP_Error(
				'mcp_suite_invalid_nonce',
				__( 'Your session has expired. Please reload the page and try again.', 'wp-mcp-suite' ),
				array( 'status' => 403 )
			);
		}

		if ( ! current_user_can( $this->required_capability() ) ) {
			AuditLogger::log(
				AuditLogger::ACTION_PERMISSION_DENIED,
				static::class,
				'rest_route',
				null,
				false,
				array( 'reason' => 'missing_capability', 'route' => $request->get_route() )
			);

			return new WP_Error(
				'mcp_suite_forbidden',
				__( 'You do not have permission to perform this action.', 'wp-mcp-suite' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	protected function success( $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( array( 'success' => true, 'data' => $data ), $status );
	}

	protected function error( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
