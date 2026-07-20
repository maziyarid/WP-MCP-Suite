<?php
/**
 * @package MCPSuite\Modules\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\ContentSync;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class OneDriveItem {

	public function __construct(
		public readonly string $item_id,
		public readonly string $drive_id,
		public readonly string $content_hash,
		public readonly string $graph_version_id,
		public readonly int $size_bytes
	) {}
}
