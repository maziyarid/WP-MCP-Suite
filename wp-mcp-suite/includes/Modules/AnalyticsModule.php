<?php
/**
 * Module 4: GA4 analytics ingestion, Phase 3.
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
use MCPSuite\Modules\Analytics\Ga4ApiException;
use MCPSuite\Modules\Analytics\GaHistoryRepository;
use MCPSuite\Modules\Analytics\GoogleGa4ApiClient;
use MCPSuite\Modules\Analytics\AnalyticsRestController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AnalyticsModule extends AbstractModule {

	/**
	 * GA4 processing is typically complete within 24-48 hours; a 2-day lag
	 * is the commonly recommended safety margin (shorter than Search
	 * Console's 3-day convention, since GA4's processing pipeline is faster).
	 */
	private const DATA_LAG_DAYS = 2;

	public function key(): string {
		return FeatureFlags::MODULE_ANALYTICS;
	}

	public function label(): string {
		return __( 'Analytics', 'wp-mcp-suite' );
	}

	public function required_capability(): string {
		return Capabilities::VIEW_ANALYTICS;
	}

	protected function roadmap_description(): string {
		return __( 'Not applicable — this module is implemented.', 'wp-mcp-suite' );
	}

	public function register(): void {
		add_action( 'mcp_suite_cron_ga_pull', array( $this, 'run_daily_pull' ) );

		if ( ! wp_next_scheduled( 'mcp_suite_cron_ga_pull' ) ) {
			wp_schedule_event( time(), 'daily', 'mcp_suite_cron_ga_pull' );
		}

		add_action(
			'rest_api_init',
			static function (): void {
				( new AnalyticsRestController() )->register_routes();
			}
		);
	}

	public function run_daily_pull( bool $force = false ): void {
		if ( ! FeatureFlags::is_enabled( FeatureFlags::MODULE_ANALYTICS ) ) {
			return;
		}

		$credentials = ( new GoogleCredentialsRepository() )->get();
		$property_id = ( new GooglePropertiesRepository() )->get_ga4_property_id();

		if ( null === $credentials || '' === $property_id ) {
			return;
		}

		$repository = new GaHistoryRepository();
		$date       = gmdate( 'Y-m-d', strtotime( '-' . self::DATA_LAG_DAYS . ' days' ) );

		if ( ! $force && $repository->has_data_for_date( $date ) ) {
			return;
		}

		$http_client = new WpHttpClient();
		$auth        = new GoogleAuthService(
			$http_client,
			new WpTransientTokenCache( 'mcp_suite_ga4_token' ),
			$credentials,
			'https://www.googleapis.com/auth/analytics.readonly'
		);
		$client = new GoogleGa4ApiClient( $http_client, $auth );

		try {
			$rows  = $client->fetch_day( $property_id, $date );
			$count = $repository->upsert_many( $rows );

			AuditLogger::log( AuditLogger::ACTION_SYNC, 'analytics', 'ga4_pull', null, true, array( 'date' => $date, 'rows' => $count ) );
		} catch ( Ga4ApiException $e ) {
			AuditLogger::log( AuditLogger::ACTION_SYNC, 'analytics', 'ga4_pull', null, false, array( 'date' => $date, 'error' => $e->getMessage() ) );
		}
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( $this->required_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-mcp-suite' ) );
		}

		AuditLogger::log( AuditLogger::ACTION_VIEW, 'analytics', 'admin_page' );

		$configured = null !== ( new GoogleCredentialsRepository() )->get() && '' !== ( new GooglePropertiesRepository() )->get_ga4_property_id();

		echo '<div class="wrap mcp-suite-module-page"><h1>' . esc_html( $this->label() ) . '</h1>';

		if ( ! $configured ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'Google service-account credentials and/or the GA4 property ID are not configured yet.', 'wp-mcp-suite' ) . ' ' .
				'<a href="' . esc_url( admin_url( 'admin.php?page=mcp-suite-settings' ) ) . '">' . esc_html__( 'Set them up on the Settings screen.', 'wp-mcp-suite' ) . '</a></p></div>';
			echo '</div>';
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mcp_ga_history';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$top_pages = $wpdb->get_results( "SELECT landing_page, sessions, users, engaged_sessions, conversions FROM {$table} ORDER BY data_date DESC, sessions DESC LIMIT 25", ARRAY_A );

		echo '<h2>' . esc_html__( 'Top Landing Pages (Most Recent Pulled Date)', 'wp-mcp-suite' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Landing Page', 'wp-mcp-suite' ) . '</th><th>' . esc_html__( 'Sessions', 'wp-mcp-suite' ) . '</th><th>' . esc_html__( 'Users', 'wp-mcp-suite' ) . '</th><th>' . esc_html__( 'Engaged Sessions', 'wp-mcp-suite' ) . '</th><th>' . esc_html__( 'Conversions', 'wp-mcp-suite' ) . '</th></tr></thead><tbody>';

		if ( empty( $top_pages ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No data pulled yet.', 'wp-mcp-suite' ) . '</td></tr>';
		} else {
			foreach ( $top_pages as $row ) {
				echo '<tr><td>' . esc_html( $row['landing_page'] ) . '</td><td>' . esc_html( $row['sessions'] ) . '</td><td>' . esc_html( $row['users'] ) . '</td><td>' . esc_html( $row['engaged_sessions'] ) . '</td><td>' . esc_html( $row['conversions'] ) . '</td></tr>';
			}
		}

		echo '</tbody></table></div>';
	}
}
