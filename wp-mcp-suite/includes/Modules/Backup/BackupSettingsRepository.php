<?php
/**
 * @package MCPSuite\Modules\Backup
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BackupSettingsRepository {

	private const OPTION_KEY = 'mcp_suite_backup_settings';

	public function get_folder_path(): string {
		$stored = get_option( self::OPTION_KEY, array() );
		return is_array( $stored ) ? (string) ( $stored['folder_path'] ?? '/MCP Suite Backups' ) : '/MCP Suite Backups';
	}

	public function get_retention_count(): int {
		$stored = get_option( self::OPTION_KEY, array() );
		$count  = is_array( $stored ) ? (int) ( $stored['retention_count'] ?? 8 ) : 8;
		return max( 1, $count );
	}

	public function save( string $folder_path, int $retention_count ): bool {
		return update_option(
			self::OPTION_KEY,
			array(
				'folder_path'     => sanitize_text_field( $folder_path ) ?: '/MCP Suite Backups',
				'retention_count' => max( 1, $retention_count ),
			)
		);
	}
}
