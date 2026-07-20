<?php
/**
 * Module 3: Search Console ingestion, Phase 3.
 *
 * @package MCPSuite\Modules
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules;

use MCPSuite\Core\AuditLogger;
use MCPSuite\Core\Capabilities;
use MCPSuite\Core\FeatureFlags;
use MCPSuite\Core\Google\GoogleAuthService;
use MCPSuite\Core\Google\GoogleCredentialsRepository;
use MCPSuite\Core\Google\GooglePropertiesRepository;
use MCPSuite\Core\Http\WpHttpClient;
use MCPSuite\Core\OAuth\WpTransientTokenCache;
use MCPSuite\Modules\SearchConsole\GoogleGscApiClient;
use MCPSuite\Modules\SearchConsole\GscApiException;
use MCPSuite\Modules\SearchConsole\GscHistoryRepository;
use MCPSuite\Modules\SearchConsole\SearchConsoleRestController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SearchConsoleModule extends AbstractModule {

	/**
	 * Search Console data typically finalizes 2-3 days after the fact;
	 * pulling "yesterday" risks incomplete numbers that would then need a
	 * correction. Pulling a fixed 3-day-old date avoids ever needing to
	 * "correct" a previously-stored row — the trade-off is data always
	 * lagging by a few days, which is the same trade-off any GSC-based
	 * rank tracker makes.
	 */
	private const DATA_LAG_DAYS = 3;

	public function key(): string {
		return FeatureFlags::MODULE_SEARCH_CONSOLE;
	}

	public function label(): string {
		return __( 'Search Console', 'wp-mcp-suite' );
	}

	public function required_capability(): string {
		return Capabilities::VIEW_ANALYTICS;
	}

	protected function roadmap_description(): string {
		return __( 'Not applicable — this module is implemented.', 'wp-mcp-suite' );
	}

	public function register(): void {
		add_action( 'mcp_suite_cron_gsc_pull', array( $this, 'run_daily_pull' ) );

		if ( ! wp_next_scheduled( 'mcp_suite_cron_gsc_pull' ) ) {
			wp_schedule_event( time(), 'daily', 'mcp_suite_cron_gsc_pull' );
		}

		add_action(
			'rest_api_init',
			static function (): void {
				( new SearchConsoleRestController() )->register_routes();
			}
		);
	}

	public function run_daily_pull( bool $force = false ): void {
		if ( ! FeatureFlags::is_enabled( FeatureFlags::MODULE_SEARCH_CONSOLE ) ) {
			return;
		}

		$credentials = ( new GoogleCredentialsRepository() )->get();
		$site_url    = ( new GooglePropertiesRepository() )->get_gsc_site_url();

		if ( null === $credentials || '' === $site_url ) {
			return; // not configured yet
		}

		$repository = new GscHistoryRepository();
		$date       = gmdate( 'Y-m-d', strtotime( '-' . self::DATA_LAG_DAYS . ' days' ) );

		if ( ! $force && $repository->has_data_for_date( $date ) ) {
			return; // already pulled this date; don't spend API quota re-fetching unchanged history
		}

		$http_client = new WpHttpClient();
		$auth        = new GoogleAuthService(
			$http_client,
			new WpTransientTokenCache( 'mcp_suite_gsc_token' ),
			$credentials,
			'https://www.googleapis.com/auth/webmasters.readonly'
		);
		$client = new GoogleGscApiClient( $http_client, $auth );

		try {
			$rows  = $client->fetch_day( $site_url, $date );
			$count = $repository->upsert_many( $rows );

			AuditLogger::log( AuditLogger::ACTION_SYNC, 'search_console', 'gsc_pull', null, true, array( 'date' => $date, 'rows' => $count ) );
		} catch ( GscApiException $e ) {
			AuditLogger::log( AuditLogger::ACTION_SYNC, 'search_console', 'gsc_pull', null, false, array( 'date' => $date, 'error' => $e->getMessage() ) );
		}
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( $this->required_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-mcp-suite' ) );
		}

		AuditLogger::log( AuditLogger::ACTION_VIEW, 'search_console', 'admin_page' );

		$configured = null !== ( new GoogleCredentialsRepository() )->get() && '' !== ( new GooglePropertiesRepository() )->get_gsc_site_url();

		echo '<div class="wrap mcp-suite-module-page"><h1>' . esc_html( $this->label() ) . '</h1>';

		if ( ! $configured ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'Google service-account credentials and/or the Search Console site URL are not configured yet.', 'wp-mcp-suite' ) . ' ' .
				'<a href="' . esc_url( admin_url( 'admin.php?page=mcp-suite-settings' ) ) . '">' . esc_html__( 'Set them up on the Settings screen.', 'wp-mcp-suite' ) . '</a></p></div>';
			echo '</div>';
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mcp_gsc_history';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$top_queries = $wpdb->get_results( "SELECT query, page_url, clicks, impressions, position FROM {$table} ORDER BY data_date DESC, clicks DESC LIMIT 25", ARRAY_A );

		echo '<h2>' . esc_html__( 'Top Queries (Most Recent Pulled Date)', 'wp-mcp-suite' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Query', 'wp-mcp-suite' ) . '</th><th>' . esc_html__( 'Page', 'wp-mcp-suite' ) . '</th><th>' . esc_html__( 'Clicks', 'wp-mcp-suite' ) . '</th><th>' . esc_html__( 'Impressions', 'wp-mcp-suite' ) . '</th><th>' . esc_html__( 'Avg. Position', 'wp-mcp-suite' ) . '</th></tr></thead><tbody>';

		if ( empty( $top_queries ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No data pulled yet.', 'wp-mcp-suite' ) . '</td></tr>';
		} else {
			foreach ( $top_queries as $row ) {
				echo '<tr><td>' . esc_html( $row['query'] ) . '</td><td>' . esc_html( $row['page_url'] ) . '</td><td>' . esc_html( $row['clicks'] ) . '</td><td>' . esc_html( $row['impressions'] ) . '</td><td>' . esc_html( number_format( (float) $row['position'], 1 ) ) . '</td></tr>';
			}
		}

		echo '</tbody></table></div>';
	}
}
