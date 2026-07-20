<?php
/**
 * @package MCPSuite\Modules\Seo
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SeoSnapshotRepository {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mcp_seo_snapshots';
	}

	/**
	 * @param SeoIssue[] $issues
	 */
	public function save( SeoSnapshot $snapshot, array $issues, string $snapshot_date ): void {
		global $wpdb;

		$issues_json = wp_json_encode( array_map( static fn( SeoIssue $i ) => $i->to_array(), $issues ) );

		// REPLACE INTO relies on the (post_id, snapshot_date) UNIQUE KEY
		// from the Phase 1 migration to make this an upsert — re-running
		// the same day's snapshot (e.g. after a mid-day content edit) never
		// creates a duplicate row for that day.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				"REPLACE INTO {$this->table()} (post_id, snapshot_date, seo_title, meta_description, focus_keyword, schema_types, robots_directives, has_breadcrumbs, source_plugin, issues_json, created_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %d, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$snapshot->post_id,
				$snapshot_date,
				$snapshot->seo_title,
				$snapshot->meta_description,
				$snapshot->focus_keyword,
				implode( ',', $snapshot->schema_types ),
				implode( ',', $snapshot->robots_directives ),
				$snapshot->has_breadcrumbs ? 1 : 0,
				$snapshot->source_plugin,
				$issues_json,
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Groups posts by identical, non-empty meta_description for one
	 * snapshot date, using SQL GROUP BY / HAVING rather than loading every
	 * description into PHP — see DuplicateDescriptionDetector::issues_for_groups().
	 *
	 * @return array<string,int[]> description => post IDs sharing it (only groups with 2+ members)
	 */
	public function find_duplicate_description_groups( string $snapshot_date ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_description, GROUP_CONCAT(post_id ORDER BY post_id) AS post_ids
				 FROM {$this->table()}
				 WHERE snapshot_date = %s AND meta_description != ''
				 GROUP BY meta_description
				 HAVING COUNT(*) > 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$snapshot_date
			),
			ARRAY_A
		);

		$groups = array();
		foreach ( $rows ?: array() as $row ) {
			$groups[ $row['meta_description'] ] = array_map( 'intval', explode( ',', $row['post_ids'] ) );
		}

		return $groups;
	}

	/**
	 * Merges additional issues (e.g. duplicate-description results) into
	 * already-saved rows' issues_json without touching any other column.
	 *
	 * @param array<int,SeoIssue> $issues_by_post_id
	 */
	public function merge_issues( array $issues_by_post_id, string $snapshot_date ): void {
		global $wpdb;

		foreach ( $issues_by_post_id as $post_id => $issue ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$existing_json = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT issues_json FROM {$this->table()} WHERE post_id = %d AND snapshot_date = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$post_id,
					$snapshot_date
				)
			);

			$existing = is_string( $existing_json ) ? json_decode( $existing_json, true ) : array();
			$existing = is_array( $existing ) ? $existing : array();

			$existing[] = $issue->to_array();

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$this->table(),
				array( 'issues_json' => wp_json_encode( $existing ) ),
				array( 'post_id' => $post_id, 'snapshot_date' => $snapshot_date ),
				array( '%s' ),
				array( '%d', '%s' )
			);
		}
	}

	/**
	 * @return array{post_id:int,issues_json:string}[]
	 */
	public function find_with_issues( string $snapshot_date, int $limit = 200 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, issues_json FROM {$this->table()} WHERE snapshot_date = %s AND issues_json != '[]' ORDER BY post_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$snapshot_date,
				$limit
			),
			ARRAY_A
		);

		return $rows ?: array();
	}
}
