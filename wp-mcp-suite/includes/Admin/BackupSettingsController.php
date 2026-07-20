<?php
/**
 * @package MCPSuite\Admin
 */

declare( strict_types = 1 );

namespace MCPSuite\Admin;

use MCPSuite\Core\AuditLogger;
use MCPSuite\Core\Capabilities;
use MCPSuite\Modules\Backup\BackupSettingsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BackupSettingsController {

	private const NONCE_ACTION = 'mcp_suite_save_backup_settings';
	private const NONCE_FIELD  = 'mcp_suite_backup_nonce';

	public function register(): void {
		add_action( 'admin_post_mcp_suite_save_backup_settings', array( $this, 'handle_save' ) );
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		if ( isset( $_GET['mcp_suite_backup_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Backup settings saved.', 'wp-mcp-suite' ) . '</p></div>';
		}

		$repository = new BackupSettingsRepository();

		echo '<h2>' . esc_html__( 'Backups', 'wp-mcp-suite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Backups reuse the same Microsoft Graph / OneDrive connection configured above for Content Sync — no separate credentials needed here.', 'wp-mcp-suite' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<input type="hidden" name="action" value="mcp_suite_save_backup_settings">';

		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="mcp_suite_backup_folder">' . esc_html__( 'Backup Folder Path', 'wp-mcp-suite' ) . '</label></th><td>' .
			'<input type="text" class="regular-text" id="mcp_suite_backup_folder" name="folder_path" value="' . esc_attr( $repository->get_folder_path() ) . '"></td></tr>';

		echo '<tr><th scope="row"><label for="mcp_suite_backup_retention">' . esc_html__( 'Retention (number of weekly backups to keep)', 'wp-mcp-suite' ) . '</label></th><td>' .
			'<input type="number" min="1" max="52" class="small-text" id="mcp_suite_backup_retention" name="retention_count" value="' . esc_attr( (string) $repository->get_retention_count() ) . '">' .
			'<p class="description">' . esc_html__( 'A verified backup is never pruned below this count, regardless of age — see the technical specification\'s retention policy.', 'wp-mcp-suite' ) . '</p></td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'Save Backup Settings', 'wp-mcp-suite' ) );
		echo '</form>';
	}

	public function handle_save(): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'wp-mcp-suite' ) );
		}

		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wp-mcp-suite' ) );
		}

		$folder_path     = isset( $_POST['folder_path'] ) ? sanitize_text_field( wp_unslash( $_POST['folder_path'] ) ) : '/MCP Suite Backups';
		$retention_count = isset( $_POST['retention_count'] ) ? absint( $_POST['retention_count'] ) : 8;

		( new BackupSettingsRepository() )->save( $folder_path, $retention_count );

		AuditLogger::log( AuditLogger::ACTION_EDIT, 'core', 'backup_settings', null, true, array( 'event' => 'settings_saved' ) );

		wp_safe_redirect( add_query_arg( array( 'page' => 'mcp-suite-settings', 'mcp_suite_backup_saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
