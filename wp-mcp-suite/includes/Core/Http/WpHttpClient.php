<?php
/**
 * Real HTTP transport using WordPress's own HTTP API. Mirrors
 * Core\Graph\WpHttpGraphClient's behavior exactly (same retry/backoff
 * policy) so the two are drop-in-consistent even though not yet merged
 * into one class — see HttpClientInterface's docblock.
 *
 * @package MCPSuite\Core\Http
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WpHttpClient implements HttpClientInterface {

	private const MAX_TRANSPORT_RETRIES = 2;

	public function request( string $method, string $url, array $headers = array(), ?string $body = null ): HttpResponse {
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
				return new HttpResponse(
					(int) wp_remote_retrieve_response_code( $response ),
					(string) wp_remote_retrieve_body( $response ),
					wp_remote_retrieve_headers( $response )->getAll()
				);
			}

			if ( $attempt > self::MAX_TRANSPORT_RETRIES ) {
				throw new HttpException(
					'Request failed at the transport level after ' . self::MAX_TRANSPORT_RETRIES . ' retries: ' . $response->get_error_message(),
					null
				);
			}

			usleep( 250000 * $attempt );
		}
	}
}
