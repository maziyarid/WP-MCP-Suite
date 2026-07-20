<?php
/**
 * @package MCPSuite\Modules\Clarity
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Clarity;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class ClarityApiException extends \RuntimeException {

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
