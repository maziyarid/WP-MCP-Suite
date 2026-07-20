<?php
/**
 * Plain data holder mirroring one row of wp_mcp_content_map. Deliberately
 * mutable (unlike the value objects elsewhere in this module) because it
 * represents a database row that gets updated in place across a sync tick
 * — an immutable "with" pattern would add ceremony without benefit here.
 *
 * @package MCPSuite\Modules\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\ContentSync;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class ContentMapRecord {

	public function __construct(
		public readonly int $id,
		public readonly int $post_id,
		public readonly string $post_type,
		public ?string $onedrive_item_id = null,
		public ?string $onedrive_path = null,
		public string $mirror_status = 'pending',
		public ?string $last_export_hash = null,
		public ?string $last_import_hash = null,
		public bool $phi_excluded = true
	) {}
}
