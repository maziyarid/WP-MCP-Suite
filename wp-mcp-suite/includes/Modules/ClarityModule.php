<?php
/**
 * Module 5: Microsoft Clarity ingestion, Phase 4.
 *
 * @package MCPSuite\Modules
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules;

use MCPSuite\Core\AuditLogger;
use MCPSuite\Core\Capabilities;
use MCPSuite\Core\FeatureFlags;
use MCPSuite\Core\Http\WpHttpClient;
use MCPSuite\Modules\Clarity\ClarityApiClient;
use MCPSuite\Modules\Clarity\ClarityApiException;
use MCPSuite\Modules\Clarity\ClarityCredentialsRepository;
use MCPSuite\Modules\Clarity\ClarityHistoryRepository;
use MCPSuite\Modules\Clarity\ClarityRestController;
use MCPSuite\Modules\Clarity\ClarityResponseParser;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ClarityModule extends AbstractModule {

	public function key(): string {
		return FeatureFlags::MODULE_CLARITY;
	}

	public function label(): string {
		return __( 'Clarity Insights', 'wp-mcp-suite' );
	}

	public function required_capability(): string {
		return Capabilities::VIEW_ANALYTICS;
	}

	protected function roadmap_description(): string {
		return __( 'Not applicable — this module is implemented.', 'wp-mcp-suite' );
	}

	public function register(): void {
		add_action( 'mcp_suite_cron_clarity_pull', array( $this, 'run_daily_pull' ) );

		if ( ! wp_next_scheduled( 'mcp_suite_cron_clarity_pull' ) ) {
			wp_schedule_event( time(), 'daily', 'mcp_suite_cron_clarity_pull' );
		}

		add_action(
			'rest_api_init',
			static function (): void {
				( new ClarityRestController() )->register_routes();
			}
		);
	}

	public function run_daily_pull( bool $force = false ): void {
		if ( ! FeatureFlags::is_enabled( FeatureFlags::MODULE_CLARITY ) ) {
			return;
		}

		$token = ( new ClarityCredentialsRepository() )->get_token();
		if ( null === $token ) {
			return;
		}

		$repository = new ClarityHistoryRepository();
		$date       = gmdate( 'Y-m-d', strtotime( '-1 day' ) );

		if ( ! $force && $repository->has_data_for_date( $date ) ) {
			return; // stay well under the 10-requests/day quota — one pull per day, ever, unless forced
		}

		$client = new ClarityApiClient( new WpHttpClient() );

		try {
			$rows  = $client->fetch_recent( $token, 1 );
			$count = $repository->upsert_many( $rows );

			AuditLogger::log( AuditLogger::ACTION_SYNC, 'clarity', 'clarity_pull', null, true, array( 'date' => $date, 'rows' => $count ) );
		} catch ( ClarityApiException $e ) {
			AuditLogger::log( AuditLogger::ACTION_SYNC, 'clarity', 'clarity_pull', null, false, array( 'date' => $date, 'error' => $e->getMessage() ) );
		}
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( $this->required_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-mcp-suite' ) );
		}

		AuditLogger::log( AuditLogger::ACTION_VIEW, 'clarity', 'admin_page' );

		echo '<div class="wrap mcp-suite-module-page"><h1>' . esc_html( $this->label() ) . '</h1>';

		if ( ! ( new ClarityCredentialsRepository() )->is_configured() ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'No Clarity API token is configured yet.', 'wp-mcp-suite' ) . ' ' .
				'<a href="' . esc_url( admin_url( 'admin.php?page=mcp-suite-settings' ) ) . '">' . esc_html__( 'Set it up on the Settings screen.', 'wp-mcp-suite' ) . '</a></p></div>';
			echo '</div>';
			return;
		}

		$unconfirmed = array_filter( ClarityResponseParser::field_mapping_confidence(), static fn( $confirmed ) => ! $confirmed );
		if ( array() !== $unconfirmed ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Rage clicks, dead clicks, quickbacks, and scroll depth use a best-effort field mapping that has not been verified against a live Clarity response (Traffic/sessions is confirmed). If these numbers look wrong, see PHASE4-NOTES.md.', 'wp-mcp-suite' ) . '</p></div>';
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mcp_clarity_history';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT page_url, sessions, rage_clicks, dead_clicks, quick_backs, avg_scroll_depth FROM {$table} ORDER BY data_date DESC, rage_clicks DESC LIMIT 25", ARRAY_A );

		echo '<h2>' . esc_html__( 'Pages with Frustration Signals (Most Recent Pulled Date)', 'wp-mcp-suite' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Page', 'wp-mcp-suite' ) . '</th><th>' . esc_html__( 'Sessions', 'wp-mcp-suite' ) . '</th><th>' . esc_html__( 'Rage Clicks', 'wp-mcp-suite' ) . '</th><th>' . esc_html__( 'Dead Clicks', 'wp-mcp-suite' ) . '</th><th>' . esc_html__( 'Quickbacks', 'wp-mcp-suite' ) . '</th><th>' . esc_html__( 'Avg. Scroll Depth', 'wp-mcp-suite' ) . '</th></tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No data pulled yet.', 'wp-mcp-suite' ) . '</td></tr>';
		} else {
			foreach ( $rows as $row ) {
				echo '<tr><td>' . esc_html( $row['page_url'] ) . '</td><td>' . esc_html( $row['sessions'] ) . '</td><td>' . esc_html( $row['rage_clicks'] ) . '</td><td>' . esc_html( $row['dead_clicks'] ) . '</td><td>' . esc_html( $row['quick_backs'] ) . '</td><td>' . esc_html( number_format( (float) $row['avg_scroll_depth'], 1 ) ) . '%</td></tr>';
			}
		}

		echo '</tbody></table></div>';
	}
}
