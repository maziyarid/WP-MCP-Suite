<?php
/**
 * @package MCPSuite\Core\Google
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Google;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class GoogleAuthException extends \RuntimeException {

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
}
