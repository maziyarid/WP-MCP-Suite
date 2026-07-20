<?php
/**
 * Migration 1.0.0 — initial schema.
 *
 * Creates the 13 custom tables required by the spec's "Database design"
 * section. Column choices are documented inline; see the accompanying
 * technical specification (§6 Data Model) for the full rationale per table.
 *
 * @package MCPSuite\Database\Migrations
 */

declare( strict_types = 1 );

namespace MCPSuite\Database\Migrations;

use MCPSuite\Database\MigrationInterface;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class Migration_1_0_0 implements MigrationInterface {

	public function version(): string {
		return '1.0.0';
	}

	public function up(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		foreach ( $this->table_definitions() as $sql ) {
			// dbDelta parses the SQL string itself; $charset_collate must be
			// interpolated per-statement, which table_definitions() already does.
			unset( $charset_collate ); // kept only to document intent above
			dbDelta( $sql );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function table_definitions(): array {
		global $wpdb;

		$prefix  = $wpdb->prefix;
		$collate = $wpdb->get_charset_collate();

		return array(
			'mcp_content_map' => "CREATE TABLE {$prefix}mcp_content_map (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id BIGINT UNSIGNED NOT NULL,
				post_type VARCHAR(20) NOT NULL,
				onedrive_drive_id VARCHAR(255) NULL,
				onedrive_item_id VARCHAR(255) NULL,
				onedrive_path TEXT NULL,
				mirror_status VARCHAR(20) NOT NULL DEFAULT 'pending',
				last_synced_at DATETIME NULL,
				last_export_hash CHAR(64) NULL,
				last_import_hash CHAR(64) NULL,
				phi_excluded TINYINT(1) NOT NULL DEFAULT 1,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY post_id (post_id),
				KEY mirror_status (mirror_status)
			) {$collate};",

			'mcp_docx_versions' => "CREATE TABLE {$prefix}mcp_docx_versions (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				content_map_id BIGINT UNSIGNED NOT NULL,
				version_no INT UNSIGNED NOT NULL,
				direction VARCHAR(10) NOT NULL,
				onedrive_version_id VARCHAR(255) NULL,
				file_checksum CHAR(64) NOT NULL,
				file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
				actor_type VARCHAR(10) NOT NULL DEFAULT 'system',
				actor_id BIGINT UNSIGNED NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY content_map_id (content_map_id),
				KEY created_at (created_at)
			) {$collate};",

			'mcp_sync_log' => "CREATE TABLE {$prefix}mcp_sync_log (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				job_id CHAR(36) NOT NULL,
				module VARCHAR(50) NOT NULL,
				object_type VARCHAR(50) NULL,
				object_id BIGINT UNSIGNED NULL,
				direction VARCHAR(20) NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'queued',
				message TEXT NULL,
				started_at DATETIME NULL,
				finished_at DATETIME NULL,
				duration_ms INT UNSIGNED NULL,
				PRIMARY KEY  (id),
				KEY job_id (job_id),
				KEY module_status (module, status)
			) {$collate};",

			'mcp_seo_snapshots' => "CREATE TABLE {$prefix}mcp_seo_snapshots (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				post_id BIGINT UNSIGNED NOT NULL,
				snapshot_date DATE NOT NULL,
				seo_title TEXT NULL,
				meta_description TEXT NULL,
				focus_keyword VARCHAR(255) NULL,
				schema_types VARCHAR(255) NULL,
				robots_directives VARCHAR(255) NULL,
				has_breadcrumbs TINYINT(1) NOT NULL DEFAULT 0,
				source_plugin VARCHAR(20) NOT NULL,
				issues_json LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY post_snapshot_date (post_id, snapshot_date)
			) {$collate};",

			'mcp_gsc_history' => "CREATE TABLE {$prefix}mcp_gsc_history (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				page_url VARCHAR(500) NOT NULL,
				query VARCHAR(500) NOT NULL,
				country CHAR(3) NOT NULL DEFAULT 'zzz',
				device VARCHAR(20) NOT NULL DEFAULT 'unknown',
				data_date DATE NOT NULL,
				clicks INT UNSIGNED NOT NULL DEFAULT 0,
				impressions INT UNSIGNED NOT NULL DEFAULT 0,
				ctr DECIMAL(7,4) NOT NULL DEFAULT 0,
				position DECIMAL(6,2) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY gsc_unique_row (page_url(191), query(191), country, device, data_date),
				KEY data_date (data_date)
			) {$collate};",

			'mcp_ga_history' => "CREATE TABLE {$prefix}mcp_ga_history (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				landing_page VARCHAR(500) NOT NULL,
				data_date DATE NOT NULL,
				sessions INT UNSIGNED NOT NULL DEFAULT 0,
				users INT UNSIGNED NOT NULL DEFAULT 0,
				engaged_sessions INT UNSIGNED NOT NULL DEFAULT 0,
				engagement_rate DECIMAL(6,4) NOT NULL DEFAULT 0,
				conversions INT UNSIGNED NOT NULL DEFAULT 0,
				event_count INT UNSIGNED NOT NULL DEFAULT 0,
				source_medium VARCHAR(191) NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY ga_unique_row (landing_page(191), data_date, source_medium(100)),
				KEY data_date (data_date)
			) {$collate};",

			'mcp_clarity_history' => "CREATE TABLE {$prefix}mcp_clarity_history (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				page_url VARCHAR(500) NOT NULL,
				data_date DATE NOT NULL,
				sessions INT UNSIGNED NOT NULL DEFAULT 0,
				rage_clicks INT UNSIGNED NOT NULL DEFAULT 0,
				dead_clicks INT UNSIGNED NOT NULL DEFAULT 0,
				quick_backs INT UNSIGNED NOT NULL DEFAULT 0,
				avg_scroll_depth DECIMAL(5,2) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY clarity_unique_row (page_url(191), data_date)
			) {$collate};",

			'mcp_uptime_events' => "CREATE TABLE {$prefix}mcp_uptime_events (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				monitor_id VARCHAR(100) NOT NULL,
				event_type VARCHAR(20) NOT NULL,
				status_code SMALLINT UNSIGNED NULL,
				response_time_ms INT UNSIGNED NULL,
				event_at DATETIME NOT NULL,
				duration_seconds INT UNSIGNED NULL,
				raw_payload_json LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY monitor_event (monitor_id, event_type),
				KEY event_at (event_at)
			) {$collate};",

			'mcp_internal_links' => "CREATE TABLE {$prefix}mcp_internal_links (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				source_post_id BIGINT UNSIGNED NULL,
				source_url VARCHAR(500) NOT NULL,
				target_post_id BIGINT UNSIGNED NULL,
				target_url VARCHAR(500) NOT NULL,
				anchor_text VARCHAR(500) NULL,
				link_type VARCHAR(20) NOT NULL DEFAULT 'contextual',
				context_snippet TEXT NULL,
				discovered_at DATETIME NOT NULL,
				last_seen_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY link_unique_row (source_url(170), target_url(170), anchor_text(170)),
				KEY target_post_id (target_post_id)
			) {$collate};",

			'mcp_backlinks' => "CREATE TABLE {$prefix}mcp_backlinks (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				referring_domain VARCHAR(255) NOT NULL,
				referring_url VARCHAR(500) NOT NULL,
				target_url VARCHAR(500) NOT NULL,
				anchor_text VARCHAR(500) NULL,
				link_attribute VARCHAR(20) NOT NULL DEFAULT 'unknown',
				authority_score SMALLINT UNSIGNED NULL,
				source_provider VARCHAR(50) NOT NULL,
				first_seen_at DATE NOT NULL,
				last_seen_at DATE NOT NULL,
				is_lost TINYINT(1) NOT NULL DEFAULT 0,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY backlink_unique_row (referring_url(191), target_url(191)),
				KEY referring_domain (referring_domain)
			) {$collate};",

			'mcp_ai_runs' => "CREATE TABLE {$prefix}mcp_ai_runs (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				job_id CHAR(36) NOT NULL,
				post_id BIGINT UNSIGNED NULL,
				provider VARCHAR(50) NOT NULL,
				model VARCHAR(100) NOT NULL,
				prompt_hash CHAR(64) NOT NULL,
				prompt_excerpt VARCHAR(500) NULL,
				output_hash CHAR(64) NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'drafted',
				human_reviewed TINYINT(1) NOT NULL DEFAULT 0,
				reviewer_id BIGINT UNSIGNED NULL,
				contains_phi_flag TINYINT(1) NOT NULL DEFAULT 0,
				performance_snapshot_json LONGTEXT NULL,
				created_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY job_id (job_id),
				KEY post_id (post_id),
				KEY status (status)
			) {$collate};",

			'mcp_backup_jobs' => "CREATE TABLE {$prefix}mcp_backup_jobs (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				job_id CHAR(36) NOT NULL,
				file_name VARCHAR(255) NOT NULL,
				file_size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
				checksum_sha256 CHAR(64) NOT NULL,
				storage_provider VARCHAR(20) NOT NULL DEFAULT 'onedrive',
				storage_path TEXT NULL,
				encrypted TINYINT(1) NOT NULL DEFAULT 1,
				encryption_method VARCHAR(50) NULL,
				retention_expires_at DATE NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				error_message TEXT NULL,
				started_at DATETIME NOT NULL,
				finished_at DATETIME NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY job_id (job_id),
				KEY status (status)
			) {$collate};",

			'mcp_audit_log' => "CREATE TABLE {$prefix}mcp_audit_log (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				event_time DATETIME NOT NULL,
				actor_id BIGINT UNSIGNED NULL,
				actor_type VARCHAR(10) NOT NULL,
				actor_ip VARCHAR(45) NULL,
				action VARCHAR(30) NOT NULL,
				object_type VARCHAR(50) NULL,
				object_id BIGINT UNSIGNED NULL,
				module VARCHAR(80) NULL,
				result VARCHAR(10) NOT NULL,
				detail_json LONGTEXT NULL,
				request_id CHAR(36) NULL,
				PRIMARY KEY  (id),
				KEY event_time (event_time),
				KEY actor_id (actor_id),
				KEY action (action),
				KEY object_lookup (object_type, object_id)
			) {$collate};",
		);
	}
}
