<?php
/**
 * @package MCPSuite\Core\Http
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Http;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class HttpResponse {

	/**
	 * @param array<string,string> $headers
	 */
	public function __construct(
		public readonly int $status,
		public readonly string $body,
		public readonly array $headers = array()
	) {}

	public function is_success(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * @return array<string,mixed>
	 * @throws HttpException If the body is not valid JSON.
	 */
	public function json(): array {
		$decoded = json_decode( $this->body, true );

		if ( ! is_array( $decoded ) ) {
			throw new HttpException( 'Response body was not valid JSON.', $this->status );
		}

		return $decoded;
	}
}
