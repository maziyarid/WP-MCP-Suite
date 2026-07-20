<?php
/**
 * Detects duplicate meta descriptions across a batch of posts. Kept
 * separate from SeoHealthChecker (which only ever sees one post at a
 * time) because this genuinely needs the whole batch to compare against —
 * it is still pure (no WordPress, no database), just pure over a
 * collection instead of a single snapshot.
 *
 * @package MCPSuite\Modules\Seo
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Seo;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class DuplicateDescriptionDetector {

	/**
	 * @param array<int,string> $descriptions_by_post_id Empty descriptions are ignored — a missing description is already flagged separately by SeoHealthChecker.
	 * @return array<int,SeoIssue> post_id => issue, only for posts that share their description with at least one other post in the batch.
	 */
	public function find( array $descriptions_by_post_id ): array {
		$groups = array();
		foreach ( $descriptions_by_post_id as $post_id => $description ) {
			$normalized = trim( $description );
			if ( '' === $normalized ) {
				continue;
			}
			$groups[ $normalized ][] = $post_id;
		}

		return $this->issues_for_groups( $groups );
	}

	/**
	 * Same output as find(), but takes pre-grouped post IDs directly —
	 * used when the grouping was already done in SQL (GROUP BY ... HAVING
	 * COUNT(*) > 1) rather than loaded into PHP memory, so a large site's
	 * duplicate-description check does not require holding every post's
	 * description in memory at once.
	 *
	 * @param array<string,int[]> $groups arbitrary group key => post IDs sharing that key. Groups with fewer than 2 post IDs are ignored.
	 * @return array<int,SeoIssue>
	 */
	public function issues_for_groups( array $groups ): array {
		$issues = array();
		foreach ( $groups as $post_ids ) {
			if ( count( $post_ids ) < 2 ) {
				continue;
			}

			foreach ( $post_ids as $post_id ) {
				$others = array_values( array_diff( $post_ids, array( $post_id ) ) );
				$issues[ $post_id ] = new SeoIssue(
					'duplicate_description',
					sprintf( 'Meta description is duplicated with %d other post(s): %s', count( $others ), implode( ', ', $others ) )
				);
			}
		}

		return $issues;
	}
}
