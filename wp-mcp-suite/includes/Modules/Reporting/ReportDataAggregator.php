<?php
/**
 * Pulls data from every module's existing repository (no new SQL queries
 * beyond the small find_all()/find_recent() additions made alongside this
 * phase) and assembles it into the sheet structure spec's "Copilot-ready
 * data layer" section calls for: one sheet per module ("domain" is
 * interpreted here as per-data-source, matching this single-site plugin's
 * actual data model — see PHASE7-NOTES.md), plus Summary, Exceptions,
 * Action Queue, and Monthly Trend.
 *
 * Returns plain arrays (sheet name => rows, each row a plain list of
 * scalar values) so the exact same structure feeds both
 * Core\Xlsx\WorkbookWriter and the JSON export — one aggregation, two
 * output formats.
 *
 * @package MCPSuite\Modules\Reporting
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Reporting;

use MCPSuite\Modules\Analytics\GaHistoryRepository;
use MCPSuite\Modules\ContentSync\WpdbContentMapRepository;
use MCPSuite\Modules\Clarity\ClarityHistoryRepository;
use MCPSuite\Modules\LinkIntelligence\BacklinkRepository;
use MCPSuite\Modules\LinkIntelligence\InternalLinkRepository;
use MCPSuite\Modules\AiWriting\AiRunRepository;
use MCPSuite\Modules\Backup\BackupJobRepository;
use MCPSuite\Modules\SearchConsole\GscHistoryRepository;
use MCPSuite\Modules\Seo\SeoSnapshotRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReportDataAggregator {

	/**
	 * @return array<string,array<int,array<int,string|int|float|bool|null>>>
	 */
	public function aggregate(): array {
		$content_sync   = $this->content_sync_rows();
		$seo            = $this->seo_rows();
		$gsc            = $this->gsc_rows();
		$ga4            = $this->ga4_rows();
		$clarity        = $this->clarity_rows();
		$internal_links = $this->internal_link_rows();
		$backlinks      = $this->backlink_rows();
		$ai_runs        = $this->ai_run_rows();
		$backups        = $this->backup_rows();

		return array(
			'Summary'         => $this->summary_rows( $content_sync, $seo, $gsc, $ga4, $ai_runs, $backups ),
			'Content Sync'    => $content_sync,
			'SEO Issues'      => $seo,
			'Search Console'  => $gsc,
			'Analytics'       => $ga4,
			'Clarity'         => $clarity,
			'Internal Links'  => $internal_links,
			'Backlinks'       => $backlinks,
			'AI Runs'         => $ai_runs,
			'Backups'         => $backups,
			'Exceptions'      => $this->exceptions_rows( $content_sync, $seo, $backups ),
			'Action Queue'    => $this->action_queue_rows( $content_sync, $ai_runs ),
			'Monthly Trend'   => $this->monthly_trend_rows( $gsc, $ga4 ),
		);
	}

	/**
	 * @return array<int,array<int,mixed>>
	 */
	private function content_sync_rows(): array {
		$rows = array( array( 'Post ID', 'Post Type', 'Status', 'PHI Excluded' ) );

		foreach ( ( new WpdbContentMapRepository() )->find_all() as $record ) {
			$rows[] = array( $record->post_id, $record->post_type, $record->mirror_status, $record->phi_excluded );
		}

		return $rows;
	}

	/**
	 * @return array<int,array<int,mixed>>
	 */
	private function seo_rows(): array {
		$rows = array( array( 'Post ID', 'Issue Count', 'Issues' ) );

		foreach ( ( new SeoSnapshotRepository() )->find_with_issues( current_time( 'Y-m-d', true ) ) as $row ) {
			$issues  = json_decode( $row['issues_json'], true ) ?: array();
			$summary = implode( '; ', array_map( static fn( $i ) => (string) ( $i['code'] ?? '' ), $issues ) );
			$rows[]  = array( (int) $row['post_id'], count( $issues ), $summary );
		}

		return $rows;
	}

	/**
	 * @return array<int,array<int,mixed>>
	 */
	private function gsc_rows(): array {
		$rows = array( array( 'Date', 'Page', 'Query', 'Clicks', 'Impressions', 'Position' ) );

		foreach ( ( new GscHistoryRepository() )->find_recent() as $row ) {
			$rows[] = array( $row['data_date'], $row['page_url'], $row['query'], (int) $row['clicks'], (int) $row['impressions'], (float) $row['position'] );
		}

		return $rows;
	}

	/**
	 * @return array<int,array<int,mixed>>
	 */
	private function ga4_rows(): array {
		$rows = array( array( 'Date', 'Landing Page', 'Sessions', 'Users', 'Conversions' ) );

		foreach ( ( new GaHistoryRepository() )->find_recent() as $row ) {
			$rows[] = array( $row['data_date'], $row['landing_page'], (int) $row['sessions'], (int) $row['users'], (int) $row['conversions'] );
		}

		return $rows;
	}

	/**
	 * @return array<int,array<int,mixed>>
	 */
	private function clarity_rows(): array {
		$rows = array( array( 'Date', 'Page', 'Sessions', 'Rage Clicks', 'Dead Clicks', 'Quickbacks' ) );

		foreach ( ( new ClarityHistoryRepository() )->find_recent() as $row ) {
			$rows[] = array( $row['data_date'], $row['page_url'], (int) $row['sessions'], (int) $row['rage_clicks'], (int) $row['dead_clicks'], (int) $row['quick_backs'] );
		}

		return $rows;
	}

	/**
	 * @return array<int,array<int,mixed>>
	 */
	private function internal_link_rows(): array {
		$rows = array( array( 'Page', 'Inbound Internal Links' ) );

		foreach ( ( new InternalLinkRepository() )->find_least_linked_pages( 100 ) as $row ) {
			$rows[] = array( $row['target_url'], (int) $row['inbound_count'] );
		}

		return $rows;
	}

	/**
	 * @return array<int,array<int,mixed>>
	 */
	private function backlink_rows(): array {
		$rows = array( array( 'Referring Domain', 'Target URL', 'Anchor Text', 'Is Lost' ) );

		foreach ( ( new BacklinkRepository() )->find_all() as $row ) {
			$rows[] = array( $row['referring_domain'], $row['target_url'], $row['anchor_text'], (bool) $row['is_lost'] );
		}

		return $rows;
	}

	/**
	 * @return array<int,array<int,mixed>>
	 */
	private function ai_run_rows(): array {
		$rows = array( array( 'Post ID', 'Provider', 'Status', 'Human Reviewed' ) );

		foreach ( ( new AiRunRepository() )->find_recent() as $run ) {
			$rows[] = array( $run->post_id, $run->provider, $run->status->value, $run->human_reviewed );
		}

		return $rows;
	}

	/**
	 * @return array<int,array<int,mixed>>
	 */
	private function backup_rows(): array {
		$rows = array( array( 'File Name', 'Status', 'Started At' ) );

		foreach ( ( new BackupJobRepository() )->find_all() as $job ) {
			$rows[] = array( $job->file_name, $job->status->value, $job->started_at );
		}

		return $rows;
	}

	/**
	 * @param array<int,array<int,mixed>> $content_sync
	 * @param array<int,array<int,mixed>> $seo
	 * @param array<int,array<int,mixed>> $gsc
	 * @param array<int,array<int,mixed>> $ga4
	 * @param array<int,array<int,mixed>> $ai_runs
	 * @param array<int,array<int,mixed>> $backups
	 * @return array<int,array<int,mixed>>
	 */
	private function summary_rows( array $content_sync, array $seo, array $gsc, array $ga4, array $ai_runs, array $backups ): array {
		$data_row_count = static fn( array $rows ) => max( 0, count( $rows ) - 1 ); // subtract the header row

		return array(
			array( 'Metric', 'Value' ),
			array( 'Report generated', current_time( 'mysql', true ) ),
			array( 'Mirrored posts', $data_row_count( $content_sync ) ),
			array( 'Posts with SEO issues (today)', $data_row_count( $seo ) ),
			array( 'Search Console rows (recent)', $data_row_count( $gsc ) ),
			array( 'Analytics rows (recent)', $data_row_count( $ga4 ) ),
			array( 'AI runs pending human review', count( ( new AiRunRepository() )->find_pending_review() ) ),
			array( 'Backup jobs on record', $data_row_count( $backups ) ),
		);
	}

	/**
	 * @param array<int,array<int,mixed>> $content_sync
	 * @param array<int,array<int,mixed>> $seo
	 * @param array<int,array<int,mixed>> $backups
	 * @return array<int,array<int,mixed>>
	 */
	private function exceptions_rows( array $content_sync, array $seo, array $backups ): array {
		$rows = array( array( 'Type', 'Detail' ) );

		foreach ( array_slice( $content_sync, 1 ) as $row ) {
			if ( 'conflict' === $row[2] ) {
				$rows[] = array( 'Sync conflict', 'Post ID ' . $row[0] );
			}
		}

		foreach ( array_slice( $seo, 1 ) as $row ) {
			$rows[] = array( 'SEO issue', 'Post ID ' . $row[0] . ': ' . $row[2] );
		}

		foreach ( array_slice( $backups, 1 ) as $row ) {
			if ( 'failed' === $row[1] ) {
				$rows[] = array( 'Backup failed', $row[0] );
			}
		}

		return $rows;
	}

	/**
	 * @param array<int,array<int,mixed>> $content_sync
	 * @param array<int,array<int,mixed>> $ai_runs
	 * @return array<int,array<int,mixed>>
	 */
	private function action_queue_rows( array $content_sync, array $ai_runs ): array {
		$rows = array( array( 'Action Needed', 'Detail' ) );

		foreach ( array_slice( $content_sync, 1 ) as $row ) {
			if ( 'conflict' === $row[2] ) {
				$rows[] = array( 'Resolve content sync conflict', 'Post ID ' . $row[0] );
			}
		}

		foreach ( array_slice( $ai_runs, 1 ) as $row ) {
			if ( ! $row[3] ) { // not human_reviewed
				$rows[] = array( 'Review AI draft', 'Post ID ' . $row[0] . ' (' . $row[1] . ')' );
			}
		}

		return $rows;
	}

	/**
	 * @param array<int,array<int,mixed>> $gsc
	 * @param array<int,array<int,mixed>> $ga4
	 * @return array<int,array<int,mixed>>
	 */
	private function monthly_trend_rows( array $gsc, array $ga4 ): array {
		$clicks_by_month   = array();
		$sessions_by_month = array();

		foreach ( array_slice( $gsc, 1 ) as $row ) {
			$month = substr( (string) $row[0], 0, 7 ); // YYYY-MM
			$clicks_by_month[ $month ] = ( $clicks_by_month[ $month ] ?? 0 ) + (int) $row[3];
		}

		foreach ( array_slice( $ga4, 1 ) as $row ) {
			$month = substr( (string) $row[0], 0, 7 );
			$sessions_by_month[ $month ] = ( $sessions_by_month[ $month ] ?? 0 ) + (int) $row[2];
		}

		$months = array_unique( array_merge( array_keys( $clicks_by_month ), array_keys( $sessions_by_month ) ) );
		sort( $months );

		$rows = array( array( 'Month', 'GSC Clicks', 'GA4 Sessions' ) );
		foreach ( $months as $month ) {
			$rows[] = array( $month, $clicks_by_month[ $month ] ?? 0, $sessions_by_month[ $month ] ?? 0 );
		}

		return $rows;
	}
}
