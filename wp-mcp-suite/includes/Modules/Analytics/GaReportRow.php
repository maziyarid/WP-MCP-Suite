<?php
/**
 * @package MCPSuite\Modules\Analytics
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class GaReportRow {

	public function __construct(
		public readonly string $landing_page,
		public readonly string $data_date,
		public readonly int $sessions,
		public readonly int $users,
		public readonly int $engaged_sessions,
		public readonly float $engagement_rate,
		public readonly int $conversions,
		public readonly int $event_count,
		public readonly string $source_medium
	) {}
}
