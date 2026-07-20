<?php
/**
 * @package MCPSuite\Modules\SearchConsole
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\SearchConsole;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GscHistoryRepository {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mcp_gsc_history';
	}

	/**
	 * @param GscQueryRow[] $rows
	 * @return int Number of rows written.
	 */
	public function upsert_many( array $rows ): int {
		global $wpdb;
		$table = $this->table();
		$now   = current_time( 'mysql', true );
		$count = 0;

		foreach ( $rows as $row ) {
			// REPLACE INTO relies on the (page_url, query, country, device,
			// data_date) UNIQUE KEY from the Phase 1 migration — re-running
			// the same day's pull is a no-op duplication-wise, it just
			// refreshes the metrics, matching spec §3.3's upsert requirement.
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare(
					"REPLACE INTO {$table} (page_url, query, country, device, data_date, clicks, impressions, ctr, position, created_at) VALUES (%s, %s, %s, %s, %s, %d, %d, %f, %f, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$row->page_url,
					$row->query,
					$row->country,
					$row->device,
					$row->data_date,
					$row->clicks,
					$row->impressions,
					$row->ctr,
					$row->position,
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
			$wpdb->prepare( "SELECT * FROM {$this->table()} ORDER BY data_date DESC, clicks DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $rows ?: array();
	}
}
