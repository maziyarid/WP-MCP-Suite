<?php
/**
 * @package MCPSuite\Modules\LinkIntelligence
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\LinkIntelligence;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BacklinkRepository {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mcp_backlinks';
	}

	/**
	 * @param Backlink[] $backlinks
	 * @return int Number of rows written.
	 */
	public function upsert_many( array $backlinks ): int {
		global $wpdb;
		$table = $this->table();
		$now   = current_time( 'mysql', true );
		$count = 0;

		foreach ( $backlinks as $link ) {
			// REPLACE INTO relies on the (referring_url, target_url)
			// UNIQUE KEY from the Phase 1 migration.
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare(
					"REPLACE INTO {$table} (referring_domain, referring_url, target_url, anchor_text, link_attribute, authority_score, source_provider, first_seen_at, last_seen_at, is_lost, created_at) VALUES (%s, %s, %s, %s, %s, %d, %s, %s, %s, 0, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$link->referring_domain,
					$link->referring_url,
					$link->target_url,
					$link->anchor_text,
					$link->link_attribute,
					$link->authority_score,
					$link->source_provider,
					$link->first_seen_at,
					$link->last_seen_at,
					$now
				)
			);
			$count++;
		}

		return $count;
	}

	/**
	 * Marks backlinks as lost if they were seen before this run but are
	 * absent from the current provider fetch — spec's is_lost column.
	 * Never deletes a backlink row: a lost link is still meaningful
	 * history (e.g. "we used to have this link, now we don't").
	 *
	 * @param string[] $current_referring_urls_for_target Every referring_url the current fetch returned, for this one target_url.
	 */
	public function mark_lost_links( string $target_url, array $current_referring_urls_for_target ): int {
		global $wpdb;
		$table = $this->table();

		if ( array() === $current_referring_urls_for_target ) {
			return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare( "UPDATE {$table} SET is_lost = 1 WHERE target_url = %s AND is_lost = 0", $target_url ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		$placeholders = implode( ',', array_fill( 0, count( $current_referring_urls_for_target ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET is_lost = 1 WHERE target_url = %s AND is_lost = 0 AND referring_url NOT IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $target_url ), $current_referring_urls_for_target )
			)
		);
	}

	/**
	 * @return array<string,mixed>[]
	 */
	public function find_all( int $limit = 500 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} ORDER BY last_seen_at DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $rows ?: array();
	}
}
