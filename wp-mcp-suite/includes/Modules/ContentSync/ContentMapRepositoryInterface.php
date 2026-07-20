<?php
/**
 * @package MCPSuite\Modules\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\ContentSync;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

interface ContentMapRepositoryInterface {

	public function find_by_post_id( int $post_id ): ?ContentMapRecord;

	public function create( int $post_id, string $post_type ): ContentMapRecord;

	/**
	 * Explicitly marks a post as safe to mirror (phi_excluded = false),
	 * creating its content_map row if one doesn't exist yet. This is the
	 * only code path allowed to change phi_excluded — SyncOrchestrator
	 * never flips it itself, per spec §2 "MUST NOT" on silent PHI
	 * exposure. Called only from an explicit, capability-gated admin
	 * action, never automatically.
	 */
	public function opt_in( int $post_id, string $post_type ): ContentMapRecord;

	public function save( ContentMapRecord $record ): void;

	/**
	 * @return ContentMapRecord[] Records flagged mirror_status = 'conflict'.
	 */
	public function find_conflicts(): array;

	/**
	 * @param 'export'|'import' $direction
	 */
	public function record_version(
		int $content_map_id,
		string $direction,
		string $file_checksum,
		int $file_size,
		?string $onedrive_version_id
	): void;
}
