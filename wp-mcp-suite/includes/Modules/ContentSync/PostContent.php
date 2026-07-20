<?php
/**
 * @package MCPSuite\Modules\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\ContentSync;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class PostContent {

	public function __construct(
		public readonly int $post_id,
		public readonly string $post_type,
		public readonly string $title,
		public readonly string $rendered_html,
		public readonly string $content_hash
	) {}
}
