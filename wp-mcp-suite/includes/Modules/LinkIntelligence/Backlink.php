<?php
/**
 * @package MCPSuite\Modules\LinkIntelligence
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\LinkIntelligence;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class Backlink {

	public function __construct(
		public readonly string $referring_domain,
		public readonly string $referring_url,
		public readonly string $target_url,
		public readonly string $anchor_text,
		public readonly string $link_attribute,
		public readonly ?int $authority_score,
		public readonly string $source_provider,
		public readonly string $first_seen_at,
		public readonly string $last_seen_at
	) {}
}
