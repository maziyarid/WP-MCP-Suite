<?php
/**
 * Abstraction over the specific Graph/OneDrive calls Content Sync needs:
 * upload a file (create or update), download a file, and resolve a stable
 * content hash for change detection. Kept narrow and specific to this
 * module's needs rather than a general-purpose Graph SDK wrapper.
 *
 * @package MCPSuite\Modules\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\ContentSync;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

interface OneDriveProviderInterface {

	/**
	 * Creates the file at $path if it does not exist, or replaces its
	 * content if it does (Graph's upload-content endpoint does both via
	 * the same PUT). Returns the resulting item metadata.
	 *
	 * @throws \MCPSuite\Core\Graph\GraphException
	 */
	public function upload( string $path, string $file_bytes ): OneDriveItem;

	/**
	 * @throws \MCPSuite\Core\Graph\GraphException
	 */
	public function download( string $item_id ): OneDriveDownload;

	/**
	 * Returns current metadata (including the change-detection hash)
	 * without downloading the file content, used during the sync-decision
	 * step so an unchanged file doesn't get fully downloaded just to be
	 * skipped.
	 *
	 * @throws \MCPSuite\Core\Graph\GraphException
	 */
	public function get_metadata( string $item_id ): OneDriveItem;
}
