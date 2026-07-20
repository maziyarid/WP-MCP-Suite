<?php
/**
 * Module 2: SEO (Rank Math / Yoast bridge), Phase 3.
 *
 * @package MCPSuite\Modules
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules;

use MCPSuite\Core\AuditLogger;
use MCPSuite\Core\Capabilities;
use MCPSuite\Core\FeatureFlags;
use MCPSuite\Modules\Seo\DuplicateDescriptionDetector;
use MCPSuite\Modules\Seo\SeoHealthChecker;
use MCPSuite\Modules\Seo\SeoMetadataProviderResolver;
use MCPSuite\Modules\Seo\SeoRestController;
use MCPSuite\Modules\Seo\SeoSnapshotRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SeoModule extends AbstractModule {

	private const BATCH_SIZE     = 50;
	private const CURSOR_OPTION  = 'mcp_suite_seo_snapshot_cursor';

	public function key(): string {
		return FeatureFlags::MODULE_SEO;
	}

	public function label(): string {
		return __( 'SEO Health', 'wp-mcp-suite' );
	}

	public function required_capability(): string {
		return Capabilities::MANAGE_SEO;
	}

	protected function roadmap_description(): string {
		return __( 'Not applicable — this module is implemented.', 'wp-mcp-suite' );
	}

	public function register(): void {
		add_action( 'mcp_suite_cron_seo_snapshot', array( $this, 'run_daily_snapshot_batch' ) );

		if ( ! wp_next_scheduled( 'mcp_suite_cron_seo_snapshot' ) ) {
			wp_schedule_event( time(), 'daily', 'mcp_suite_cron_seo_snapshot' );
		}

		add_action(
			'rest_api_init',
			static function (): void {
				( new SeoRestController() )->register_routes();
			}
		);
	}

	/**
	 * Processes up to BATCH_SIZE published posts per tick, resuming from a
	 * stored cursor — same bounded/resumable shape as Content Sync's cron
	 * (spec §4 non-functional requirements). When a batch comes back
	 * smaller than BATCH_SIZE, that tick has reached the end of today's
	 * post list, so the cross-post duplicate-description pass runs once,
	 * and the cursor resets for tomorrow's run.
	 */
	public function run_daily_snapshot_batch(): void {
		if ( ! FeatureFlags::is_enabled( FeatureFlags::MODULE_SEO ) ) {
			return;
		}

		$provider = ( new SeoMetadataProviderResolver() )->resolve();

		if ( null === $provider ) {
			return; // neither Rank Math nor Yoast is active; nothing to read
		}

		global $wpdb;
		$cursor         = (int) get_option( self::CURSOR_OPTION, 0 );
		$public_types   = get_post_types( array( 'public' => true ), 'names' );
		$placeholders   = implode( ',', array_fill( 0, count( $public_types ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders}) AND ID > %d ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( array_values( $public_types ), array( $cursor, self::BATCH_SIZE ) )
			)
		);

		$checker        = new SeoHealthChecker();
		$repository     = new SeoSnapshotRepository();
		$snapshot_date  = current_time( 'Y-m-d', true );
		$processed      = 0;

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;

			try {
				$snapshot = $provider->get_snapshot( $post_id );

				$content     = (string) apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );
				$word_count  = str_word_count( wp_strip_all_tags( $content ) );

				// Per-page "this should be noindexed" overrides (e.g. a
				// utility page) are not yet supported — every publicly
				// queryable, published post is currently treated as
				// expected-to-be-indexed. See technical specification for
				// this as a documented, deliberate scope limit rather than
				// a guessed heuristic.
				$issues = $checker->check( $snapshot, $word_count, true );

				$repository->save( $snapshot, $issues, $snapshot_date );
			} catch ( \Throwable $e ) {
				AuditLogger::log( AuditLogger::ACTION_SYNC, 'seo', 'post', $post_id, false, array( 'error' => $e->getMessage() ) );
			}

			update_option( self::CURSOR_OPTION, $post_id );
			$processed++;
		}

		AuditLogger::log(
			AuditLogger::ACTION_SYNC,
			'seo',
			'snapshot_batch',
			null,
			true,
			array( 'processed' => $processed, 'provider' => get_class( $provider ) )
		);

		if ( $processed < self::BATCH_SIZE ) {
			$this->run_duplicate_description_pass( $repository, $snapshot_date );
			update_option( self::CURSOR_OPTION, 0 );
		}
	}

	private function run_duplicate_description_pass( SeoSnapshotRepository $repository, string $snapshot_date ): void {
		$groups = $repository->find_duplicate_description_groups( $snapshot_date );

		if ( array() === $groups ) {
			return;
		}

		$issues = ( new DuplicateDescriptionDetector() )->issues_for_groups( $groups );
		$repository->merge_issues( $issues, $snapshot_date );

		AuditLogger::log( AuditLogger::ACTION_SYNC, 'seo', 'duplicate_description_pass', null, true, array( 'affected_posts' => count( $issues ) ) );
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( $this->required_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-mcp-suite' ) );
		}

		AuditLogger::log( AuditLogger::ACTION_VIEW, 'seo', 'admin_page' );

		$provider = ( new SeoMetadataProviderResolver() )->resolve();

		echo '<div class="wrap mcp-suite-module-page"><h1>' . esc_html( $this->label() ) . '</h1>';

		if ( null === $provider ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Neither Rank Math nor Yoast SEO is active. Install one of them to use this module.', 'wp-mcp-suite' ) . '</p></div></div>';
			return;
		}

		$repository    = new SeoSnapshotRepository();
		$snapshot_date = current_time( 'Y-m-d', true );
		$rows          = $repository->find_with_issues( $snapshot_date );

		echo '<p>' . esc_html( sprintf( __( 'Source: %s. Snapshot date: %s.', 'wp-mcp-suite' ), $provider instanceof \MCPSuite\Modules\Seo\RankMathAdapter ? 'Rank Math' : 'Yoast SEO', $snapshot_date ) ) . '</p>';

		echo '<h2>' . esc_html__( 'Posts with Issues Today', 'wp-mcp-suite' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Post', 'wp-mcp-suite' ) . '</th><th>' . esc_html__( 'Issues', 'wp-mcp-suite' ) . '</th></tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="2">' . esc_html__( 'No issues found in today\'s snapshot yet (or the daily scan has not run).', 'wp-mcp-suite' ) . '</td></tr>';
		} else {
			foreach ( $rows as $row ) {
				$issues = json_decode( $row['issues_json'], true ) ?: array();
				$title  = get_the_title( (int) $row['post_id'] ) ?: '#' . $row['post_id'];

				echo '<tr><td><a href="' . esc_url( get_edit_post_link( (int) $row['post_id'] ) ) . '">' . esc_html( $title ) . '</a></td><td><ul style="margin:0">';
				foreach ( $issues as $issue ) {
					echo '<li>' . esc_html( (string) ( $issue['message'] ?? $issue['code'] ?? '' ) ) . '</li>';
				}
				echo '</ul></td></tr>';
			}
		}

		echo '</tbody></table></div>';
	}
}
