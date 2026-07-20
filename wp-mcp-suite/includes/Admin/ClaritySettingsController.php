<?php
/**
 * @package MCPSuite\Admin
 */

declare( strict_types = 1 );

namespace MCPSuite\Admin;

use MCPSuite\Core\AuditLogger;
use MCPSuite\Core\Capabilities;
use MCPSuite\Core\Encryption;
use MCPSuite\Modules\Clarity\ClarityCredentialsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ClaritySettingsController {

	private const NONCE_ACTION = 'mcp_suite_save_clarity_token';
	private const NONCE_FIELD  = 'mcp_suite_clarity_nonce';

	public function register(): void {
		add_action( 'admin_post_mcp_suite_save_clarity_token', array( $this, 'handle_save' ) );
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		if ( isset( $_GET['mcp_suite_clarity_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Clarity token saved.', 'wp-mcp-suite' ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Microsoft Clarity', 'wp-mcp-suite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Generate a token in your Clarity project: Settings → Data Export → Generate new API token. This is a static token, not an OAuth flow — treat it like a password.', 'wp-mcp-suite' ) . '</p>';

		if ( ! Encryption::has_key() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'MCP_SUITE_ENCRYPTION_KEY is not set in wp-config.php. The token cannot be saved until it is configured.', 'wp-mcp-suite' ) . '</p></div>';
			return;
		}

		$configured = ( new ClarityCredentialsRepository() )->is_configured();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<input type="hidden" name="action" value="mcp_suite_save_clarity_token">';

		echo '<table class="form-table"><tbody><tr><th scope="row"><label for="mcp_suite_clarity_token">' . esc_html__( 'API Token', 'wp-mcp-suite' ) . '</label></th><td>' .
			'<input type="password" class="regular-text" id="mcp_suite_clarity_token" name="token" placeholder="' . esc_attr( $configured ? __( 'Leave blank to keep the current token', 'wp-mcp-suite' ) : '' ) . '" autocomplete="off"></td></tr></tbody></table>';

		submit_button( __( 'Save Clarity Token', 'wp-mcp-suite' ) );
		echo '</form>';
	}

	public function handle_save(): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'wp-mcp-suite' ) );
		}

		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wp-mcp-suite' ) );
		}

		$repository    = new ClarityCredentialsRepository();
		$token_input   = isset( $_POST['token'] ) ? (string) wp_unslash( $_POST['token'] ) : '';

		if ( '' !== trim( $token_input ) ) {
			$repository->save( $token_input );
			AuditLogger::log( AuditLogger::ACTION_EDIT, 'core', 'clarity_credentials', null, true, array( 'event' => 'token_saved' ) );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'mcp-suite-settings', 'mcp_suite_clarity_saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
