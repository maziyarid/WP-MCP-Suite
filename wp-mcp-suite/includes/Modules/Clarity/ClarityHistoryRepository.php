<?php
/**
 * @package MCPSuite\Modules\Clarity
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Clarity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ClarityHistoryRepository {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mcp_clarity_history';
	}

	/**
	 * @param ClarityPageMetrics[] $rows
	 * @return int Number of rows written.
	 */
	public function upsert_many( array $rows ): int {
		global $wpdb;
		$table = $this->table();
		$now   = current_time( 'mysql', true );
		$count = 0;

		foreach ( $rows as $row ) {
			// REPLACE INTO relies on the (page_url, data_date) UNIQUE KEY
			// from the Phase 1 migration.
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare(
					"REPLACE INTO {$table} (page_url, data_date, sessions, rage_clicks, dead_clicks, quick_backs, avg_scroll_depth, created_at) VALUES (%s, %s, %d, %d, %d, %d, %f, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$row->page_url,
					$row->data_date,
					$row->sessions,
					$row->rage_clicks,
					$row->dead_clicks,
					$row->quick_backs,
					$row->avg_scroll_depth,
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
			$wpdb->prepare( "SELECT * FROM {$this->table()} ORDER BY data_date DESC, rage_clicks DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $rows ?: array();
	}
}
