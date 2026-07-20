<?php
/**
 * Module 6: Internal + backlink graph, Phase 4.
 *
 * @package MCPSuite\Modules
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules;

use MCPSuite\Core\AuditLogger;
use MCPSuite\Core\Capabilities;
use MCPSuite\Core\FeatureFlags;
use MCPSuite\Modules\LinkIntelligence\BacklinkProviderInterface;
use MCPSuite\Modules\LinkIntelligence\BacklinkRepository;
use MCPSuite\Modules\LinkIntelligence\InternalLinkExtractor;
use MCPSuite\Modules\LinkIntelligence\InternalLinkRepository;
use MCPSuite\Modules\LinkIntelligence\LinkIntelligenceRestController;
use MCPSuite\Modules\LinkIntelligence\NullBacklinkProvider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LinkIntelligenceModule extends AbstractModule {

	private const BATCH_SIZE    = 50;
	private const CURSOR_OPTION = 'mcp_suite_link_graph_cursor';

	public function key(): string {
		return FeatureFlags::MODULE_LINK_INTEL;
	}

	public function label(): string {
		return __( 'Link Intelligence', 'wp-mcp-suite' );
	}

	public function required_capability(): string {
		return Capabilities::VIEW_ANALYTICS;
	}

	protected function roadmap_description(): string {
		return __( 'Not applicable — this module is implemented.', 'wp-mcp-suite' );
	}

	/**
	 * Swap in a real provider once one is chosen — see
	 * BacklinkProviderInterface's docblock. Nothing else in this module
	 * needs to change.
	 */
	private function backlink_provider(): BacklinkProviderInterface {
		return new NullBacklinkProvider();
	}

	public function register(): void {
		add_action( 'mcp_suite_cron_link_graph_rebuild', array( $this, 'run_internal_link_batch' ) );
		add_action( 'mcp_suite_cron_backlink_refresh', array( $this, 'run_backlink_refresh' ) );

		if ( ! wp_next_scheduled( 'mcp_suite_cron_link_graph_rebuild' ) ) {
			wp_schedule_event( time(), 'daily', 'mcp_suite_cron_link_graph_rebuild' );
		}
		if ( ! wp_next_scheduled( 'mcp_suite_cron_backlink_refresh' ) ) {
			wp_schedule_event( time(), 'weekly', 'mcp_suite_cron_backlink_refresh' );
		}

		add_action(
			'rest_api_init',
			static function (): void {
				( new LinkIntelligenceRestController() )->register_routes();
			}
		);
	}

	/**
	 * Bounded/resumable crawl of published content's rendered HTML,
	 * matching the same shape as Content Sync's and SEO's cron batches.
	 */
	public function run_internal_link_batch(): void {
		if ( ! FeatureFlags::is_enabled( FeatureFlags::MODULE_LINK_INTEL ) ) {
			return;
		}

		global $wpdb;
		$cursor       = (int) get_option( self::CURSOR_OPTION, 0 );
		$public_types = array_values( get_post_types( array( 'public' => true ), 'names' ) );
		$placeholders = implode( ',', array_fill( 0, count( $public_types ), '%s' ) );
		$site_host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders}) AND ID > %d ORDER BY ID ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( $public_types, array( $cursor, self::BATCH_SIZE ) )
			)
		);

		$extractor  = new InternalLinkExtractor();
		$repository = new InternalLinkRepository();
		$processed  = 0;

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;

			try {
				$permalink = get_permalink( $post_id );
				$content   = (string) apply_filters( 'the_content', get_post_field( 'post_content', $post_id ) );

				$links = $extractor->extract( $content, (string) $permalink, $site_host );
				$repository->upsert_many( $links );
				$repository->prune_removed_links( (string) $permalink, array_map( static fn( $l ) => $l->target_url, $links ) );
			} catch ( \Throwable $e ) {
				AuditLogger::log( AuditLogger::ACTION_SYNC, 'link_intelligence', 'post', $post_id, false, array( 'error' => $e->getMessage() ) );
			}

			update_option( self::CURSOR_OPTION, $post_id );
			$processed++;
		}

		if ( $processed < self::BATCH_SIZE ) {
			update_option( self::CURSOR_OPTION, 0 );
		}

		AuditLogger::log( AuditLogger::ACTION_SYNC, 'link_intelligence', 'internal_link_batch', null, true, array( 'processed' => $processed ) );
	}

	public function run_backlink_refresh(): void {
		if ( ! FeatureFlags::is_enabled( FeatureFlags::MODULE_LINK_INTEL ) ) {
			return;
		}

		$provider = $this->backlink_provider();

		if ( ! $provider->is_configured() ) {
			return; // honest no-op — see BacklinkProviderInterface's docblock
		}

		$site_host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$backlinks  = $provider->fetch_for_domain( $site_host );
		$repository = new BacklinkRepository();
		$count      = $repository->upsert_many( $backlinks );

		$by_target = array();
		foreach ( $backlinks as $link ) {
			$by_target[ $link->target_url ][] = $link->referring_url;
		}
		foreach ( $by_target as $target_url => $referring_urls ) {
			$repository->mark_lost_links( $target_url, $referring_urls );
		}

		AuditLogger::log( AuditLogger::ACTION_SYNC, 'link_intelligence', 'backlink_refresh', null, true, array( 'rows' => $count ) );
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( $this->required_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-mcp-suite' ) );
		}

		AuditLogger::log( AuditLogger::ACTION_VIEW, 'link_intelligence', 'admin_page' );

		echo '<div class="wrap mcp-suite-module-page"><h1>' . esc_html( $this->label() ) . '</h1>';

		if ( ! $this->backlink_provider()->is_configured() ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'No backlink data provider is configured yet — this requires choosing a specific provider (see the technical specification\'s open decisions). Internal link data below is unaffected.', 'wp-mcp-suite' ) . '</p></div>';
		}

		$repository = new InternalLinkRepository();
		$least_linked = $repository->find_least_linked_pages( 25 );

		echo '<h2>' . esc_html__( 'Least-Linked Internal Pages', 'wp-mcp-suite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Pages with the fewest internal links pointing to them — candidates for more internal linking.', 'wp-mcp-suite' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Page', 'wp-mcp-suite' ) . '</th><th>' . esc_html__( 'Inbound Internal Links', 'wp-mcp-suite' ) . '</th></tr></thead><tbody>';

		if ( empty( $least_linked ) ) {
			echo '<tr><td colspan="2">' . esc_html__( 'No internal link data yet — the daily crawl has not completed a pass.', 'wp-mcp-suite' ) . '</td></tr>';
		} else {
			foreach ( $least_linked as $row ) {
				echo '<tr><td>' . esc_html( $row['target_url'] ) . '</td><td>' . esc_html( $row['inbound_count'] ) . '</td></tr>';
			}
		}

		echo '</tbody></table></div>';
	}
}
