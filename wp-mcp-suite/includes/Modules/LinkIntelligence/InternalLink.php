<?php
/**
 * @package MCPSuite\Modules\LinkIntelligence
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\LinkIntelligence;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class InternalLink {

	public function __construct(
		public readonly string $source_url,
		public readonly string $target_url,
		public readonly string $anchor_text,
		public readonly string $link_type,
		public readonly string $context_snippet
	) {}
}
