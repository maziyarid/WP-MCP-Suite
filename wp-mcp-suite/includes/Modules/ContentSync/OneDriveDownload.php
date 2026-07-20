<?php
/**
 * @package MCPSuite\Modules\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\ContentSync;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class OneDriveDownload {

	public function __construct(
		public readonly string $file_bytes,
		public readonly OneDriveItem $item
	) {}
}
