<?php
/**
 * Module 8: Encrypted database backups to OneDrive, Phase 6.
 *
 * Pipeline: dump (DatabaseDumper) -> compress (BackupArchiver) -> encrypt
 * (Core\Encryption) -> upload (GraphBackupUploader, resumable) -> verify
 * (download + re-hash, not just trusting Graph's own hash algorithm) ->
 * only ever after verification succeeds, prune old backups
 * (BackupRetentionPolicy). A failure at any stage marks the job 'failed'
 * and stops — it never marks a job 'verified' on partial success.
 *
 * @package MCPSuite\Modules
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules;

use MCPSuite\Core\AuditLogger;
use MCPSuite\Core\Capabilities;
use MCPSuite\Core\Encryption;
use MCPSuite\Core\FeatureFlags;
use MCPSuite\Core\Graph\GraphAuthService;
use MCPSuite\Core\Graph\GraphCredentialsRepository;
use MCPSuite\Core\Graph\GraphException;
use MCPSuite\Core\Graph\WpHttpGraphClient;
use MCPSuite\Core\OAuth\WpTransientTokenCache;
use MCPSuite\Modules\Backup\BackupArchiver;
use MCPSuite\Modules\Backup\BackupException;
use MCPSuite\Modules\Backup\BackupJobRepository;
use MCPSuite\Modules\Backup\BackupRestController;
use MCPSuite\Modules\Backup\BackupRetentionPolicy;
use MCPSuite\Modules\Backup\BackupSettingsRepository;
use MCPSuite\Modules\Backup\DatabaseDumper;
use MCPSuite\Modules\Backup\GraphBackupUploader;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BackupModule extends AbstractModule {

	public function key(): string {
		return FeatureFlags::MODULE_BACKUP;
	}

	public function label(): string {
		return __( 'Backups', 'wp-mcp-suite' );
	}

	public function required_capability(): string {
		return Capabilities::MANAGE_BACKUPS;
	}

	protected function roadmap_description(): string {
		return __( 'Not applicable — this module is implemented.', 'wp-mcp-suite' );
	}

	public function register(): void {
		// WordPress core does not ship a 'monthly' cron schedule (only
		// hourly/twicedaily/daily/weekly) — register one, since the archive
		// prune job needs it and scheduling against a non-existent
		// recurrence key would silently fail to schedule anything.
		add_filter( 'cron_schedules', array( $this, 'register_monthly_cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval

		add_action( 'mcp_suite_cron_backup_weekly', array( $this, 'run_backup' ) );
		add_action( 'mcp_suite_cron_archive_prune', array( $this, 'run_retention_prune' ) );

		if ( ! wp_next_scheduled( 'mcp_suite_cron_backup_weekly' ) ) {
			wp_schedule_event( time(), 'weekly', 'mcp_suite_cron_backup_weekly' );
		}
		if ( ! wp_next_scheduled( 'mcp_suite_cron_archive_prune' ) ) {
			wp_schedule_event( time(), 'mcp_suite_monthly', 'mcp_suite_cron_archive_prune' );
		}

		add_action(
			'rest_api_init',
			static function (): void {
				( new BackupRestController() )->register_routes();
			}
		);
	}

	/**
	 * @param array<string,array{interval:int,display:string}> $schedules
	 * @return array<string,array{interval:int,display:string}>
	 */
	public function register_monthly_cron_schedule( array $schedules ): array {
		$schedules['mcp_suite_monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Once Monthly (MCP Suite)', 'wp-mcp-suite' ),
		);

		return $schedules;
	}

	public function run_backup(): void {
		if ( ! FeatureFlags::is_enabled( FeatureFlags::MODULE_BACKUP ) ) {
			return;
		}

		$graph_credentials = ( new GraphCredentialsRepository() )->get();
		if ( null === $graph_credentials ) {
			return; // not configured; nothing to log as a failure
		}

		if ( ! Encryption::has_key() ) {
			AuditLogger::log( AuditLogger::ACTION_BACKUP, 'backup', 'weekly_backup', null, false, array( 'error' => 'MCP_SUITE_ENCRYPTION_KEY is not configured' ) );
			return;
		}

		$job_id     = wp_generate_uuid4();
		$file_name  = 'backup-' . gmdate( 'Y-m-d-His' ) . '.sql.gz.enc';
		$repository = new BackupJobRepository();
		$job_record_id = $repository->start( $job_id, $file_name );

		try {
			// Stage 1: dump + compress. The dumper yields piece by piece;
			// gzencode() needs the whole payload at once (PHP's zlib
			// extension has no simple streaming-compress API for arbitrary
			// chunk sizes), so pieces are concatenated before compression.
			// For very large databases this is a real memory ceiling — see
			// PHASE6-NOTES.md.
			$sql = '';
			foreach ( ( new DatabaseDumper() )->dump() as $piece ) {
				$sql .= $piece;
			}

			$compressed = ( new BackupArchiver() )->compress( $sql );
			unset( $sql ); // free the uncompressed copy before encrypting

			$encrypted = Encryption::encrypt( $compressed );
			$checksum  = hash( 'sha256', $encrypted );
			unset( $compressed );

			// Stage 2: upload.
			$http_client = new WpHttpGraphClient();
			$auth        = new GraphAuthService( $http_client, new WpTransientTokenCache( 'mcp_suite_graph_token' ), $graph_credentials );
			$uploader    = new GraphBackupUploader( $http_client, $auth, $graph_credentials->drive_id );

			$folder_path  = ( new BackupSettingsRepository() )->get_folder_path();
			$storage_path = rtrim( $folder_path, '/' ) . '/' . $file_name;

			$uploader->upload( $storage_path, $encrypted );

			$repository->mark_uploaded( $job_record_id, strlen( $encrypted ), $checksum, $storage_path, 'sodium_crypto_secretbox' );

			// Stage 3: verify — download what was just uploaded and re-hash
			// it locally, rather than trusting Graph's own (different-
			// algorithm) content hash as a stand-in for "uploaded correctly."
			$redownloaded = $uploader->download( $storage_path );
			unset( $encrypted );

			if ( hash( 'sha256', $redownloaded ) !== $checksum ) {
				throw new BackupException( 'Post-upload verification failed: the re-downloaded file\'s checksum does not match what was uploaded.' );
			}

			$retention_days = 90; // informational retention_expires_at column; actual pruning is count-based via BackupRetentionPolicy, not date-based
			$repository->mark_verified( $job_record_id, gmdate( 'Y-m-d', strtotime( "+{$retention_days} days" ) ) );

			AuditLogger::log( AuditLogger::ACTION_BACKUP, 'backup', 'weekly_backup', $job_record_id, true, array( 'file_size_bytes' => strlen( $redownloaded ) ) );
		} catch ( \Throwable $e ) {
			$repository->mark_failed( $job_record_id, $e->getMessage() );
			AuditLogger::log( AuditLogger::ACTION_BACKUP, 'backup', 'weekly_backup', $job_record_id, false, array( 'error' => $e->getMessage() ) );
		}
	}

	public function run_retention_prune(): void {
		if ( ! FeatureFlags::is_enabled( FeatureFlags::MODULE_BACKUP ) ) {
			return;
		}

		$graph_credentials = ( new GraphCredentialsRepository() )->get();
		if ( null === $graph_credentials ) {
			return;
		}

		$repository      = new BackupJobRepository();
		$retention_count = ( new BackupSettingsRepository() )->get_retention_count();

		$to_prune = ( new BackupRetentionPolicy() )->jobs_to_prune( $repository->find_all(), $retention_count );

		if ( array() === $to_prune ) {
			return;
		}

		$http_client = new WpHttpGraphClient();
		$auth        = new GraphAuthService( $http_client, new WpTransientTokenCache( 'mcp_suite_graph_token' ), $graph_credentials );
		$uploader    = new GraphBackupUploader( $http_client, $auth, $graph_credentials->drive_id );

		$pruned_count = 0;
		foreach ( $to_prune as $job ) {
			$path = $repository->get_storage_path( $job->id );
			if ( null === $path ) {
				continue;
			}

			try {
				$uploader->delete( $path );
				$repository->mark_purged( $job->id );
				$pruned_count++;
			} catch ( GraphException $e ) {
				AuditLogger::log( AuditLogger::ACTION_BACKUP, 'backup', 'retention_prune', $job->id, false, array( 'error' => $e->getMessage() ) );
			}
		}

		AuditLogger::log( AuditLogger::ACTION_BACKUP, 'backup', 'retention_prune', null, true, array( 'pruned' => $pruned_count, 'kept_minimum' => $retention_count ) );
	}

	public function render_admin_page(): void {
		if ( ! current_user_can( $this->required_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-mcp-suite' ) );
		}

		AuditLogger::log( AuditLogger::ACTION_VIEW, 'backup', 'admin_page' );

		echo '<div class="wrap mcp-suite-module-page"><h1>' . esc_html( $this->label() ) . '</h1>';

		if ( null === ( new GraphCredentialsRepository() )->get() ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'Microsoft Graph is not configured yet (Backups reuse the same OneDrive connection as Content Sync).', 'wp-mcp-suite' ) . ' ' .
				'<a href="' . esc_url( admin_url( 'admin.php?page=mcp-suite-settings' ) ) . '">' . esc_html__( 'Set it up on the Settings screen.', 'wp-mcp-suite' ) . '</a></p></div>';
		}

		$jobs = ( new BackupJobRepository() )->find_all();

		echo '<h2>' . esc_html__( 'Backup History', 'wp-mcp-suite' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'File', 'wp-mcp-suite' ) . '</th><th>' . esc_html__( 'Status', 'wp-mcp-suite' ) . '</th><th>' . esc_html__( 'Started', 'wp-mcp-suite' ) . '</th></tr></thead><tbody>';

		if ( empty( $jobs ) ) {
			echo '<tr><td colspan="3">' . esc_html__( 'No backups have run yet.', 'wp-mcp-suite' ) . '</td></tr>';
		} else {
			foreach ( $jobs as $job ) {
				$status_color = match ( $job->status->value ) {
					'verified' => '#1a7f37',
					'failed'   => '#a00',
					default    => '#666',
				};
				echo '<tr><td>' . esc_html( $job->file_name ) . '</td><td><span style="color:' . esc_attr( $status_color ) . '">' . esc_html( $job->status->value ) . '</span></td><td>' . esc_html( $job->started_at ) . '</td></tr>';
			}
		}

		echo '</tbody></table></div>';
	}
}
