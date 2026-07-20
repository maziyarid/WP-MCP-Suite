<?php
/**
 * @package MCPSuite\Core\Http
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Http;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

class HttpException extends \RuntimeException {

	public function __construct(
		string $message,
		private readonly ?int $http_status = null,
		?\Throwable $previous = null
	) {
		parent::__construct( $message, 0, $previous );
	}

	public function http_status(): ?int {
		return $this->http_status;
	}

	public function is_retryable(): bool {
		return in_array( $this->http_status, array( 429, 502, 503, 504 ), true );
	}
}
