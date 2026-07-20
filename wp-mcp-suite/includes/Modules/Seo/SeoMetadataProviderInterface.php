<?php
/**
 * Read-only bridge to Rank Math or Yoast's metadata. This module never
 * writes back into either plugin's own tables/postmeta — spec §3.2:
 * "Read-only with respect to Rank Math/Yoast's own tables."
 *
 * @package MCPSuite\Modules\Seo
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Seo;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

interface SeoMetadataProviderInterface {

	public function is_available(): bool;

	public function get_snapshot( int $post_id ): SeoSnapshot;
}
