<?php
/**
 * @package MCPSuite\Modules\SearchConsole
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\SearchConsole;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class GscQueryRow {

	public function __construct(
		public readonly string $page_url,
		public readonly string $query,
		public readonly string $country,
		public readonly string $device,
		public readonly string $data_date,
		public readonly int $clicks,
		public readonly int $impressions,
		public readonly float $ctr,
		public readonly float $position
	) {}
}
