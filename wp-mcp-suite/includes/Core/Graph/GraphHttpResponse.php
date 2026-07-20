<?php
/**
 * @package MCPSuite\Core\Graph
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Graph;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class GraphHttpResponse {

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
	 * @throws GraphException If the body is not valid JSON.
	 */
	public function json(): array {
		$decoded = json_decode( $this->body, true );

		if ( ! is_array( $decoded ) ) {
			throw new GraphException(
				'Graph response body was not valid JSON.',
				$this->status,
				null
			);
		}

		return $decoded;
	}

	/**
	 * Graph error responses look like: {"error":{"code":"...","message":"..."}}
	 */
	public function graph_error_code(): ?string {
		try {
			$json = $this->json();
		} catch ( GraphException $e ) {
			return null;
		}

		return $json['error']['code'] ?? null;
	}
}
