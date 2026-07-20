<?php
/**
 * @package MCPSuite\Modules\Analytics
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GaHistoryRepository {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mcp_ga_history';
	}

	/**
	 * @param GaReportRow[] $rows
	 * @return int Number of rows written.
	 */
	public function upsert_many( array $rows ): int {
		global $wpdb;
		$table = $this->table();
		$now   = current_time( 'mysql', true );
		$count = 0;

		foreach ( $rows as $row ) {
			// REPLACE INTO relies on the (landing_page, data_date,
			// source_medium) UNIQUE KEY from the Phase 1 migration.
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare(
					"REPLACE INTO {$table} (landing_page, data_date, sessions, users, engaged_sessions, engagement_rate, conversions, event_count, source_medium, created_at) VALUES (%s, %s, %d, %d, %d, %f, %d, %d, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$row->landing_page,
					$row->data_date,
					$row->sessions,
					$row->users,
					$row->engaged_sessions,
					$row->engagement_rate,
					$row->conversions,
					$row->event_count,
					$row->source_medium,
					$now
				)
			);
			$count++;
		}

		return $count;
	}

	public function has_data_for_date( string $date ): bool {
		global $wpdb;

		$found = $wpdb->get_var(
			$wpdb->prepare( "SELECT 1 FROM {$this->table()} WHERE data_date = %s LIMIT 1", $date ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return null !== $found;
	}

	/**
	 * @return array<string,mixed>[]
	 */
	public function find_recent( int $limit = 100 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} ORDER BY data_date DESC, sessions DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $rows ?: array();
	}
}
