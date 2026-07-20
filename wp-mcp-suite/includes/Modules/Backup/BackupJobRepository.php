<?php
/**
 * @package MCPSuite\Modules\Backup
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BackupJobRepository {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mcp_backup_jobs';
	}

	public function start( string $job_id, string $file_name ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array(
				'job_id'      => $job_id,
				'file_name'   => $file_name,
				'status'      => BackupJobStatus::PENDING->value,
				'encrypted'   => 1,
				'started_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function mark_uploaded( int $id, int $file_size_bytes, string $checksum_sha256, string $storage_path, string $encryption_method ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array(
				'file_size_bytes'   => $file_size_bytes,
				'checksum_sha256'   => $checksum_sha256,
				'storage_provider'  => 'onedrive',
				'storage_path'      => $storage_path,
				'encryption_method' => $encryption_method,
				'status'            => BackupJobStatus::UPLOADED->value,
			),
			array( 'id' => $id ),
			array( '%d', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function mark_verified( int $id, string $retention_expires_at ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array( 'status' => BackupJobStatus::VERIFIED->value, 'retention_expires_at' => $retention_expires_at, 'finished_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function mark_failed( int $id, string $error_message ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array( 'status' => BackupJobStatus::FAILED->value, 'error_message' => mb_substr( $error_message, 0, 2000 ), 'finished_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function mark_purged( int $id ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array( 'status' => BackupJobStatus::PURGED->value ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @return BackupJob[]
	 */
	public function find_all(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT id, job_id, file_name, storage_path, status, started_at FROM {$this->table()} ORDER BY started_at DESC", ARRAY_A );

		return array_map( array( $this, 'hydrate' ), $rows ?: array() );
	}

	public function get_storage_path( int $id ): ?string {
		global $wpdb;

		$path = $wpdb->get_var(
			$wpdb->prepare( "SELECT storage_path FROM {$this->table()} WHERE id = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return is_string( $path ) ? $path : null;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function hydrate( array $row ): BackupJob {
		return new BackupJob(
			id: (int) $row['id'],
			job_id: (string) $row['job_id'],
			file_name: (string) $row['file_name'],
			status: BackupJobStatus::from( (string) $row['status'] ),
			started_at: (string) $row['started_at'],
			storage_path: isset( $row['storage_path'] ) ? (string) $row['storage_path'] : null
		);
	}
}
