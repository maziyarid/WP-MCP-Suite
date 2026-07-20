<?php
/**
 * @package MCPSuite\Modules\Clarity
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Clarity;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class ClarityPageMetrics {

	public function __construct(
		public readonly string $page_url,
		public readonly string $data_date,
		public readonly int $sessions,
		public readonly int $rage_clicks,
		public readonly int $dead_clicks,
		public readonly int $quick_backs,
		public readonly float $avg_scroll_depth
	) {}
}
