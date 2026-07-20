<?php
/**
 * Module 9: Consolidated Excel/CSV/JSON reporting, Phase 7.
 *
 * @package MCPSuite\Modules
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules;

use MCPSuite\Core\AuditLogger;
use MCPSuite\Core\Capabilities;
use MCPSuite\Core\FeatureFlags;
use MCPSuite\Core\Graph\GraphAuthService;
use MCPSuite\Core\Graph\GraphCredentialsRepository;
use MCPSuite\Core\Graph\WpHttpGraphClient;
use MCPSuite\Core\OAuth\WpTransientTokenCache;
use MCPSuite\Core\Xlsx\WorkbookWriter;
use MCPSuite\Modules\Backup\GraphBackupUploader;
use MCPSuite\Modules\Reporting\ReportDataAggregator;
use MCPSuite\Modules\Reporting\ReportJsonTransformer;
use MCPSuite\Modules\Reporting\ReportingRestController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReportingModule extends AbstractModule {

	private const REPORT_FOLDER = '/MCP Suite Reports';

	public function key(): string {
		return FeatureFlags::MODULE_REPORTING;
	}

	public function label(): string {
		return __( 'Reporting', 'wp-mcp-suite' );
	}

	public function required_capability(): string {
		return Capabilities::VIEW_ANALYTICS;
	}

	protected function roadmap_description(): string {
		return __( 'Not applicable — this module is implemented.', 'wp-mcp-suite' );
	}

	public function register(): void {
		add_action( 'mcp_suite_cron_report_rebuild', array( $this, 'run_report_rebuild' ) );

		if ( ! wp_next_scheduled( 'mcp_suite_cron_report_rebuild' ) ) {
			wp_schedule_event( time(), 'weekly', 'mcp_suite_cron_report_rebuild' );
		}

		add_action(
			'rest_api_init',
			static function (): void {
				( new ReportingRestController() )->register_routes();
			}
		);
	}

	/**
	 * Regenerates the workbook and JSON export locally (in the WordPress
	 * uploads directory, always) and additionally uploads both to OneDrive
	 * when Graph is configured — reporting is useful even to a site that
	 * hasn't set up Content Sync/Backups, so OneDrive upload is a bonus,
	 * not a requirement, unlike those two modules.
	 */
	public function run_report_rebuild(): void {
		if ( ! FeatureFlags::is_enabled( FeatureFlags::MODULE_REPORTING ) ) {
			return;
		}

		try {
			$sheets = ( new ReportDataAggregator() )->aggregate();

			$xlsx_bytes = ( new WorkbookWriter() )->build( $sheets );
			$json_bytes = (string) wp_json_encode( ( new ReportJsonTransformer() )->transform( $sheets ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );

			$upload_dir = wp_upload_dir();
			$local_dir  = trailingslashit( $upload_dir['basedir'] ) . 'mcp-suite-reports/';
			wp_mkdir_p( $local_dir );

			file_put_contents( $local_dir . 'latest-report.xlsx', $xlsx_bytes );
			file_put_contents( $local_dir . 'latest-report.json', $json_bytes );

			$graph_credentials = ( new GraphCredentialsRepository() )->get();
			if ( null !== $graph_credentials ) {
				$http_client = new WpHttpGraphClient();
				$auth        = new GraphAuthService( $http_client, new WpTransientTokenCache( 'mcp_suite_graph_token' ), $graph_credentials );
				// GraphBackupUploader is a generic resumable-upload client
				// despite its name (see that class's docblock) — reused
				// here rather than duplicating the same upload-session logic.
				$uploader = new GraphBackupUploader( $http_client, $auth, $graph_credentials->drive_id );

				$uploader->upload( self::REPORT_FOLDER . '/latest-report.xlsx', $xlsx_bytes );
				$uploader->upload( self::REPORT_FOLDER . '/latest-report.json', $json_bytes );
			}

			update_option( 'mcp_suite_report_last_generated', current_time( 'mysql', true ) );

			AuditLogger::log( AuditLogger::ACTION_EXPORT, 'reporting', 'workbook', null, true, array( 'sheets' => count( $sheets ), 'onedrive_uploaded' => null !== $graph_credentials ) );
		} catch ( \Throwable $e ) {
			AuditLogger::log( AuditLogger::ACTION_EXPORT, 'reporting', 'workbook', null, false, array( 'error' => $e->getMessage() ) );
		}
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( $this->required_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-mcp-suite' ) );
		}

		AuditLogger::log( AuditLogger::ACTION_VIEW, 'reporting', 'admin_page' );

		$upload_dir = wp_upload_dir();
		$local_url  = trailingslashit( $upload_dir['baseurl'] ) . 'mcp-suite-reports/';
		$xlsx_path  = trailingslashit( $upload_dir['basedir'] ) . 'mcp-suite-reports/latest-report.xlsx';

		echo '<div class="wrap mcp-suite-module-page"><h1>' . esc_html( $this->label() ) . '</h1>';

		$last_generated = get_option( 'mcp_suite_report_last_generated', '' );

		if ( '' === $last_generated || ! file_exists( $xlsx_path ) ) {
			echo '<p>' . esc_html__( 'No report has been generated yet. It regenerates weekly, or trigger it now via the REST route.', 'wp-mcp-suite' ) . '</p>';
		} else {
			echo '<p>' . esc_html( sprintf( __( 'Last generated: %s', 'wp-mcp-suite' ), $last_generated ) ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( $local_url . 'latest-report.xlsx' ) . '">' . esc_html__( 'Download Excel Workbook', 'wp-mcp-suite' ) . '</a> ' .
				'<a class="button" href="' . esc_url( $local_url . 'latest-report.json' ) . '">' . esc_html__( 'Download JSON Export', 'wp-mcp-suite' ) . '</a></p>';
		}

		echo '<p>' . esc_html__( 'The workbook includes one sheet per module (Content Sync, SEO, Search Console, Analytics, Clarity, Internal Links, Backlinks, AI Runs, Backups) plus Summary, Exceptions, Action Queue, and Monthly Trend sheets.', 'wp-mcp-suite' ) . '</p>';

		echo '</div>';
	}
}
