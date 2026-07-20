<?php
/**
 * @package MCPSuite\Modules\Seo
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Seo;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class SeoIssue {

	public function __construct(
		public readonly string $code,
		public readonly string $message
	) {}

	/**
	 * @return array{code:string,message:string}
	 */
	public function to_array(): array {
		return array( 'code' => $this->code, 'message' => $this->message );
	}
}
