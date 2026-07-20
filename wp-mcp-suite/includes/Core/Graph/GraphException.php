<?php
/**
 * @package MCPSuite\Core\Graph
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Graph;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class GraphException extends \RuntimeException {

	public function __construct(
		string $message,
		private readonly ?int $http_status = null,
		private readonly ?string $graph_error_code = null,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 0, $previous );
	}

	public function http_status(): ?int {
		return $this->http_status;
	}

	public function graph_error_code(): ?string {
		return $this->graph_error_code;
	}

	/**
	 * Whether this failure is plausibly transient and worth a bounded
	 * retry (rate limiting, gateway timeouts) as opposed to a
	 * configuration/permission problem that will fail identically on
	 * retry (invalid credentials, forbidden scope).
	 */
	public function is_retryable(): bool {
		return in_array( $this->http_status, array( 429, 502, 503, 504 ), true );
	}
}
