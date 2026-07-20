<?php
/**
 * @package MCPSuite\Modules\LinkIntelligence
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\LinkIntelligence;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class InternalLinkRepository {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mcp_internal_links';
	}

	/**
	 * @param InternalLink[] $links
	 * @return int Number of rows written.
	 */
	public function upsert_many( array $links ): int {
		global $wpdb;
		$table = $this->table();
		$now   = current_time( 'mysql', true );
		$count = 0;

		foreach ( $links as $link ) {
			$source_post_id = url_to_postid( $link->source_url ) ?: null;
			$target_post_id = url_to_postid( $link->target_url ) ?: null;

			// REPLACE INTO relies on the (source_url, target_url,
			// anchor_text) UNIQUE KEY from the Phase 1 migration.
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare(
					"REPLACE INTO {$table} (source_post_id, source_url, target_post_id, target_url, anchor_text, link_type, context_snippet, discovered_at, last_seen_at) VALUES (%d, %s, %d, %s, %s, %s, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$source_post_id,
					$link->source_url,
					$target_post_id,
					$link->target_url,
					$link->anchor_text,
					$link->link_type,
					$link->context_snippet,
					$now,
					$now
				)
			);
			$count++;
		}

		return $count;
	}

	/**
	 * Deletes link rows sourced from a given post that were NOT part of
	 * this crawl's results, so a removed link disappears from the graph
	 * instead of persisting forever as stale data.
	 *
	 * @param string[] $current_target_urls
	 */
	public function prune_removed_links( string $source_url, array $current_target_urls ): void {
		global $wpdb;
		$table = $this->table();

		if ( array() === $current_target_urls ) {
			$wpdb->delete( $table, array( 'source_url' => $source_url ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $current_target_urls ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE source_url = %s AND target_url NOT IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array( $source_url ), $current_target_urls )
			)
		);
	}

	/**
	 * @return array{target_url:string,inbound_count:int}[] Internal pages ranked by inbound internal link count — useful for spotting orphaned or under-linked content.
	 */
	public function find_least_linked_pages( int $limit = 25 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT target_url, COUNT(*) AS inbound_count FROM {$this->table()} GROUP BY target_url ORDER BY inbound_count ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			),
			ARRAY_A
		);

		return $rows ?: array();
	}
}
