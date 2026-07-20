<?php
/**
 * Module 1: Content Sync (WordPress <-> Word <-> OneDrive), Phase 2.
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
use MCPSuite\Modules\ContentSync\ContentSyncMetaBox;
use MCPSuite\Modules\ContentSync\ContentSyncRestController;
use MCPSuite\Modules\ContentSync\GraphOneDriveProvider;
use MCPSuite\Modules\ContentSync\PhpWordDocxConverter;
use MCPSuite\Modules\ContentSync\SyncEngine;
use MCPSuite\Modules\ContentSync\SyncOrchestrator;
use MCPSuite\Modules\ContentSync\SyncResultLogger;
use MCPSuite\Modules\ContentSync\WpContentProvider;
use MCPSuite\Modules\ContentSync\WpdbContentMapRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ContentSyncModule extends AbstractModule {

	/**
	 * Non-functional requirement (spec §4): cron jobs must be bounded and
	 * resumable, never process an unbounded set in one tick.
	 */
	private const BATCH_SIZE = 20;

	private const CURSOR_OPTION = 'mcp_suite_content_sync_cursor';

	public function key(): string {
		return FeatureFlags::MODULE_CONTENT_SYNC;
	}

	public function label(): string {
		return __( 'Content Sync', 'wp-mcp-suite' );
	}

	public function required_capability(): string {
		return Capabilities::MANAGE_CONTENT;
	}

	protected function roadmap_description(): string {
		return __( 'Not applicable — this module is implemented.', 'wp-mcp-suite' );
	}

	public function register(): void {
		add_action( 'mcp_suite_cron_content_sync', array( $this, 'run_scheduled_batch' ) );

		if ( ! wp_next_scheduled( 'mcp_suite_cron_content_sync' ) ) {
			wp_schedule_event( time(), 'hourly', 'mcp_suite_cron_content_sync' );
		}

		add_action(
			'rest_api_init',
			static function (): void {
				( new ContentSyncRestController() )->register_routes();
			}
		);

		( new ContentSyncMetaBox() )->register();
	}

	/**
	 * Cron entry point. Processes up to BATCH_SIZE opted-in posts per tick,
	 * resuming from the last processed post_id rather than always starting
	 * from the top — so a site with more opted-in posts than fit in one
	 * batch still makes steady progress across ticks instead of the same
	 * first N posts being synced every hour while the rest never are.
	 */
	public function run_scheduled_batch(): void {
		if ( ! FeatureFlags::is_enabled( FeatureFlags::MODULE_CONTENT_SYNC ) ) {
			return; // defence in depth: cron could theoretically fire mid-disable
		}

		$credentials = ( new GraphCredentialsRepository() )->get();

		if ( null === $credentials ) {
			return; // not configured yet; nothing to do, nothing to log as a failure
		}

		global $wpdb;
		$table  = $wpdb->prefix . 'mcp_content_map';
		$cursor = (int) get_option( self::CURSOR_OPTION, 0 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$table} WHERE phi_excluded = 0 AND post_id > %d ORDER BY post_id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cursor,
				self::BATCH_SIZE
			)
		);

		if ( empty( $post_ids ) ) {
			update_option( self::CURSOR_OPTION, 0 ); // wrap around for the next tick
			return;
		}

		$http_client  = new WpHttpGraphClient();
		$auth         = new GraphAuthService( $http_client, new WpTransientTokenCache( 'mcp_suite_graph_token' ), $credentials );
		$onedrive     = new GraphOneDriveProvider( $http_client, $auth, $credentials->drive_id );
		$orchestrator = new SyncOrchestrator(
			new WpContentProvider(),
			$onedrive,
			new PhpWordDocxConverter(),
			new WpdbContentMapRepository(),
			new SyncEngine(),
			$credentials->base_folder_path
		);
		$logger = new SyncResultLogger();

		foreach ( $post_ids as $post_id ) {
			try {
				$result = $orchestrator->sync_post( (int) $post_id );
				$logger->log( $result );
			} catch ( \Throwable $e ) {
				// A single post's unexpected failure must not stop the batch
				// or crash the cron run — log and move on to the next post.
				AuditLogger::log(
					AuditLogger::ACTION_SYNC,
					'content_sync',
					'post',
					(int) $post_id,
					false,
					array( 'error' => $e->getMessage() )
				);
			}

			update_option( self::CURSOR_OPTION, (int) $post_id );
		}
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( $this->required_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-mcp-suite' ) );
		}

		AuditLogger::log( AuditLogger::ACTION_VIEW, 'content_sync', 'admin_page' );

		$credentials_configured = null !== ( new GraphCredentialsRepository() )->get();

		echo '<div class="wrap mcp-suite-module-page">';
		echo '<h1>' . esc_html( $this->label() ) . '</h1>';

		if ( ! $credentials_configured ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'Microsoft Graph is not configured yet.', 'wp-mcp-suite' ) . ' ' .
				'<a href="' . esc_url( admin_url( 'admin.php?page=mcp-suite-settings' ) ) . '">' .
				esc_html__( 'Set it up on the Settings screen.', 'wp-mcp-suite' ) . '</a></p></div>';
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mcp_content_map';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT post_id, post_type, mirror_status, phi_excluded, last_synced_at FROM {$table} ORDER BY updated_at DESC LIMIT 100", ARRAY_A );

		echo '<h2>' . esc_html__( 'Mirrored Content', 'wp-mcp-suite' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' .
			esc_html__( 'Post', 'wp-mcp-suite' ) . '</th><th>' .
			esc_html__( 'Type', 'wp-mcp-suite' ) . '</th><th>' .
			esc_html__( 'Status', 'wp-mcp-suite' ) . '</th><th>' .
			esc_html__( 'Last Synced', 'wp-mcp-suite' ) . '</th></tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No posts have been opted into content sync yet. Open a post and use "Opt into Content Sync" to add it.', 'wp-mcp-suite' ) . '</td></tr>';
		} else {
			foreach ( $rows as $row ) {
				$title = get_the_title( (int) $row['post_id'] ) ?: '#' . $row['post_id'];
				echo '<tr>' .
					'<td>' . esc_html( $title ) . '</td>' .
					'<td>' . esc_html( $row['post_type'] ) . '</td>' .
					'<td>' . esc_html( $row['mirror_status'] ) . ( 'conflict' === $row['mirror_status'] ? ' ⚠️' : '' ) . '</td>' .
					'<td>' . esc_html( (string) ( $row['last_synced_at'] ?? '—' ) ) . '</td>' .
					'</tr>';
			}
		}

		echo '</tbody></table></div>';
	}
}
