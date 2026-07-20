<?php
/**
 * @package MCPSuite\Modules\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\ContentSync;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

interface ContentProviderInterface {

	/**
	 * @throws \RuntimeException If the post does not exist.
	 */
	public function get_content( int $post_id ): PostContent;

	/**
	 * Imports HTML pulled from the OneDrive mirror as a new WordPress
	 * revision on the given post — never as a direct overwrite of the
	 * published version. Returns the new revision ID.
	 */
	public function create_revision_from_import( int $post_id, string $html ): int;
}
