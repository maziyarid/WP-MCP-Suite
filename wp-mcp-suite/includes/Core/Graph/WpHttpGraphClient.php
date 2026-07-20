<?php
/**
 * Real HTTP transport for Microsoft Graph, using WordPress's own HTTP API
 * (wp_remote_request) rather than raw curl, so it inherits WP's proxy
 * config, SSL verification, and filters. This is the only class in the
 * plugin allowed to call wp_remote_request() for Graph traffic.
 *
 * @package MCPSuite\Core\Graph
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Graph;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WpHttpGraphClient implements GraphHttpClientInterface {

	/**
	 * Bounded retry count for transport-level failures only (DNS, timeout).
	 * HTTP error status codes are returned normally, not retried here —
	 * retrying a 4xx/5xx is the caller's decision (see GraphException::is_retryable()).
	 */
	private const MAX_TRANSPORT_RETRIES = 2;

	public function request( string $method, string $url, array $headers = array(), ?string $body = null ): GraphHttpResponse {
		$attempt = 0;

		while ( true ) {
			$attempt++;

			$response = wp_remote_request(
				$url,
				array(
					'method'  => $method,
					'headers' => $headers,
					'body'    => $body,
					'timeout' => 30,
				)
			);

			if ( ! is_wp_error( $response ) ) {
				return new GraphHttpResponse(
					(int) wp_remote_retrieve_response_code( $response ),
					(string) wp_remote_retrieve_body( $response ),
					wp_remote_retrieve_headers( $response )->getAll()
				);
			}

			if ( $attempt > self::MAX_TRANSPORT_RETRIES ) {
				throw new GraphException(
					'Graph request failed at the transport level after ' . self::MAX_TRANSPORT_RETRIES . ' retries: ' . $response->get_error_message(),
					null,
					null
				);
			}

			// Bounded exponential backoff before retrying a transport failure.
			usleep( 250000 * $attempt );
		}
	}
}
