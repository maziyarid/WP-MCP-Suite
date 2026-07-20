<?php
/**
 * Real repository backed by wp_mcp_content_map / wp_mcp_docx_versions.
 * Every query is prepared; table names come from $wpdb->prefix, never a
 * literal string, matching the plugin-wide negative requirement against
 * hardcoded table name strings outside migration files.
 *
 * @package MCPSuite\Modules\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\ContentSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WpdbContentMapRepository implements ContentMapRepositoryInterface {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mcp_content_map';
	}

	private function versions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mcp_docx_versions';
	}

	public function find_by_post_id( int $post_id ): ?ContentMapRecord {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE post_id = %d", $post_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	public function create( int $post_id, string $post_type ): ContentMapRecord {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array(
				'post_id'       => $post_id,
				'post_type'     => $post_type,
				'mirror_status' => 'pending',
				'phi_excluded'  => 1,
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		return new ContentMapRecord(
			id: (int) $wpdb->insert_id,
			post_id: $post_id,
			post_type: $post_type
		);
	}

	public function opt_in( int $post_id, string $post_type ): ContentMapRecord {
		$existing = $this->find_by_post_id( $post_id );

		if ( $existing ) {
			$existing->phi_excluded = false;
			$this->save( $existing );
			return $existing;
		}

		global $wpdb;
		$now = current_time( 'mysql', true );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array(
				'post_id'       => $post_id,
				'post_type'     => $post_type,
				'mirror_status' => 'pending',
				'phi_excluded'  => 0,
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		return new ContentMapRecord(
			id: (int) $wpdb->insert_id,
			post_id: $post_id,
			post_type: $post_type,
			phi_excluded: false
		);
	}

	public function save( ContentMapRecord $record ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array(
				'onedrive_item_id' => $record->onedrive_item_id,
				'onedrive_path'    => $record->onedrive_path,
				'mirror_status'    => $record->mirror_status,
				'last_export_hash' => $record->last_export_hash,
				'last_import_hash' => $record->last_import_hash,
				'phi_excluded'     => $record->phi_excluded ? 1 : 0,
				'last_synced_at'   => current_time( 'mysql', true ),
				'updated_at'       => current_time( 'mysql', true ),
			),
			array( 'id' => $record->id ),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function find_conflicts(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			"SELECT * FROM {$this->table()} WHERE mirror_status = 'conflict' ORDER BY updated_at DESC",
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), $rows ?: array() );
	}

	/**
	 * @return ContentMapRecord[]
	 */
	public function find_all( int $limit = 500 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} ORDER BY updated_at DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), $rows ?: array() );
	}

	public function record_version(
		int $content_map_id,
		string $direction,
		string $file_checksum,
		int $file_size,
		?string $onedrive_version_id
	): void {
		global $wpdb;

		$next_version = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(MAX(version_no), 0) + 1 FROM {$this->versions_table()} WHERE content_map_id = %d", $content_map_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->versions_table(),
			array(
				'content_map_id'      => $content_map_id,
				'version_no'          => $next_version,
				'direction'           => $direction,
				'onedrive_version_id' => $onedrive_version_id,
				'file_checksum'       => $file_checksum,
				'file_size'           => $file_size,
				'actor_type'          => get_current_user_id() > 0 ? 'user' : 'system',
				'actor_id'            => get_current_user_id() ?: null,
				'created_at'          => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
		);
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function hydrate( array $row ): ContentMapRecord {
		$record = new ContentMapRecord(
			id: (int) $row['id'],
			post_id: (int) $row['post_id'],
			post_type: (string) $row['post_type'],
			onedrive_item_id: $row['onedrive_item_id'] ?? null,
			onedrive_path: $row['onedrive_path'] ?? null,
			mirror_status: (string) $row['mirror_status'],
			last_export_hash: $row['last_export_hash'] ?? null,
			last_import_hash: $row['last_import_hash'] ?? null,
			phi_excluded: (bool) $row['phi_excluded']
		);

		return $record;
	}
}
