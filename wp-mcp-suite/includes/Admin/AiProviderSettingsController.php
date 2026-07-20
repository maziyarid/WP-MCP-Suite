<?php
/**
 * @package MCPSuite\Admin
 */

declare( strict_types = 1 );

namespace MCPSuite\Admin;

use MCPSuite\Core\AuditLogger;
use MCPSuite\Core\Capabilities;
use MCPSuite\Core\Encryption;
use MCPSuite\Modules\AiWriting\AiProviderCredentialsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AiProviderSettingsController {

	private const NONCE_ACTION = 'mcp_suite_save_ai_provider';
	private const NONCE_FIELD  = 'mcp_suite_ai_provider_nonce';

	public function register(): void {
		add_action( 'admin_post_mcp_suite_save_ai_provider', array( $this, 'handle_save' ) );
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		if ( isset( $_GET['mcp_suite_ai_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'AI provider settings saved.', 'wp-mcp-suite' ) . '</p></div>';
		}

		$repository = new AiProviderCredentialsRepository();
		$settings   = $repository->get();

		echo '<h2>' . esc_html__( 'AI Writing Provider', 'wp-mcp-suite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Works with any OpenAI-compatible chat-completions API — OpenAI itself, or any provider exposing the same endpoint contract. No single vendor is hardcoded.', 'wp-mcp-suite' ) . '</p>';

		if ( ! Encryption::has_key() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'MCP_SUITE_ENCRYPTION_KEY is not set in wp-config.php. Settings cannot be saved until it is configured.', 'wp-mcp-suite' ) . '</p></div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<input type="hidden" name="action" value="mcp_suite_save_ai_provider">';

		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="mcp_suite_ai_base_url">' . esc_html__( 'API Base URL', 'wp-mcp-suite' ) . '</label></th><td>' .
			'<input type="text" class="regular-text" id="mcp_suite_ai_base_url" name="base_url" value="' . esc_attr( $settings['base_url'] ?? '' ) . '" placeholder="https://api.openai.com/v1"></td></tr>';

		echo '<tr><th scope="row"><label for="mcp_suite_ai_api_key">' . esc_html__( 'API Key', 'wp-mcp-suite' ) . '</label></th><td>' .
			'<input type="password" class="regular-text" id="mcp_suite_ai_api_key" name="api_key" placeholder="' . esc_attr( $settings ? __( 'Leave blank to keep the current key', 'wp-mcp-suite' ) : '' ) . '" autocomplete="off"></td></tr>';

		echo '<tr><th scope="row"><label for="mcp_suite_ai_model">' . esc_html__( 'Model', 'wp-mcp-suite' ) . '</label></th><td>' .
			'<input type="text" class="regular-text" id="mcp_suite_ai_model" name="model" value="' . esc_attr( $settings['model'] ?? '' ) . '" placeholder="gpt-4o-mini"></td></tr>';

		$blocklist = $repository->get_blocked_keyword_phrases();
		echo '<tr><th scope="row"><label for="mcp_suite_ai_blocklist">' . esc_html__( 'Additional PHI Blocklist Phrases', 'wp-mcp-suite' ) . '</label></th><td>' .
			'<textarea class="large-text" rows="4" id="mcp_suite_ai_blocklist" name="blocklist_phrases">' . esc_textarea( implode( "\n", $blocklist ) ) . '</textarea>' .
			'<p class="description">' . esc_html__( 'One phrase per line (e.g. specific patient names your clinic must never send to the AI provider). Matched case-insensitively as plain text. This is in addition to the built-in structural checks (SSN-like numbers, MRN patterns, explicit patient-identifying phrasing).', 'wp-mcp-suite' ) . '</p></td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'Save AI Provider Settings', 'wp-mcp-suite' ) );
		echo '</form>';
	}

	public function handle_save(): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'wp-mcp-suite' ) );
		}

		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wp-mcp-suite' ) );
		}

		$repository = new AiProviderCredentialsRepository();
		$existing   = $repository->get();

		$base_url = isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( $_POST['base_url'] ) ) : '';
		$model    = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';

		$api_key_input = isset( $_POST['api_key'] ) ? (string) wp_unslash( $_POST['api_key'] ) : '';
		$api_key       = '' !== trim( $api_key_input ) ? $api_key_input : ( $existing['api_key'] ?? '' );

		if ( '' === $base_url || '' === $api_key || '' === $model ) {
			wp_die( esc_html__( 'API Base URL, API Key, and Model are all required.', 'wp-mcp-suite' ) );
		}

		$repository->save( $base_url, $api_key, $model );

		$blocklist_input = isset( $_POST['blocklist_phrases'] ) ? (string) wp_unslash( $_POST['blocklist_phrases'] ) : '';
		$phrases         = array_filter( array_map( 'trim', explode( "\n", $blocklist_input ) ) );
		$repository->save_blocked_keyword_phrases( $phrases );

		AuditLogger::log( AuditLogger::ACTION_EDIT, 'core', 'ai_provider_settings', null, true, array( 'event' => 'settings_saved' ) );

		wp_safe_redirect( add_query_arg( array( 'page' => 'mcp-suite-settings', 'mcp_suite_ai_saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
