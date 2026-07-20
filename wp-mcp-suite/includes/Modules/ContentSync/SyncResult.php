<?php
/**
 * @package MCPSuite\Modules\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\ContentSync;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class SyncResult {

	/**
	 * @param string[] $warnings
	 */
	public function __construct(
		public readonly int $post_id,
		public readonly SyncDecision $decision,
		public readonly bool $success,
		public readonly string $message = '',
		public readonly array $warnings = array()
	) {}
}
