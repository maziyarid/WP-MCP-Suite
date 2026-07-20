<?php
/**
 * Renders and handles the Microsoft Graph credentials form on the plugin
 * Settings screen. Registered unconditionally (not gated by a module
 * feature flag) because credentials must be configurable before any module
 * that depends on them can be turned on.
 *
 * @package MCPSuite\Admin
 */

declare( strict_types = 1 );

namespace MCPSuite\Admin;

use MCPSuite\Core\AuditLogger;
use MCPSuite\Core\Capabilities;
use MCPSuite\Core\Encryption;
use MCPSuite\Core\Graph\GraphCredentialsRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GraphSettingsController {

	private const NONCE_ACTION = 'mcp_suite_save_graph_credentials';
	private const NONCE_FIELD  = 'mcp_suite_graph_nonce';

	public function register(): void {
		add_action( 'admin_post_mcp_suite_save_graph_credentials', array( $this, 'handle_save' ) );
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		if ( isset( $_GET['mcp_suite_graph_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Microsoft Graph credentials saved.', 'wp-mcp-suite' ) . '</p></div>';
		}

		$repository  = new GraphCredentialsRepository();
		$credentials = $repository->get();

		echo '<h2>' . esc_html__( 'Microsoft Graph (OneDrive)', 'wp-mcp-suite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Application permissions (client-credentials flow) — requires a Microsoft 365 tenant, an Azure AD app registration, and admin-consented Files.ReadWrite.All (or Sites.ReadWrite.All) Graph API permissions. Personal/consumer OneDrive accounts are not supported.', 'wp-mcp-suite' ) . '</p>';

		if ( ! Encryption::has_key() ) {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'MCP_SUITE_ENCRYPTION_KEY is not set in wp-config.php. Credentials cannot be saved until it is configured. Suggested value (generate a new one, do not reuse this example):', 'wp-mcp-suite' ) .
				'</p><code>define( \'MCP_SUITE_ENCRYPTION_KEY\', \'' . esc_html( Encryption::generate_key() ) . '\' );</code></div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<input type="hidden" name="action" value="mcp_suite_save_graph_credentials">';

		echo '<table class="form-table"><tbody>';
		$this->text_row( 'tenant_id', __( 'Azure Tenant ID', 'wp-mcp-suite' ), $credentials->tenant_id ?? '' );
		$this->text_row( 'client_id', __( 'Application (Client) ID', 'wp-mcp-suite' ), $credentials->client_id ?? '' );
		$this->password_row( 'client_secret', __( 'Client Secret', 'wp-mcp-suite' ), null !== $credentials );
		$this->text_row( 'drive_id', __( 'OneDrive Drive ID', 'wp-mcp-suite' ), $credentials->drive_id ?? '' );
		$this->text_row( 'base_folder_path', __( 'Mirror Folder Path', 'wp-mcp-suite' ), $credentials->base_folder_path ?? '/MCP Suite Content Mirror' );
		echo '</tbody></table>';

		submit_button( __( 'Save Graph Credentials', 'wp-mcp-suite' ) );
		echo '</form>';
	}

	private function text_row( string $name, string $label, string $value ): void {
		echo '<tr><th scope="row"><label for="mcp_suite_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>' .
			'<input type="text" class="regular-text" id="mcp_suite_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"></td></tr>';
	}

	private function password_row( string $name, string $label, bool $already_set ): void {
		$placeholder = $already_set ? __( 'Leave blank to keep the current secret', 'wp-mcp-suite' ) : '';
		echo '<tr><th scope="row"><label for="mcp_suite_' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td>' .
			'<input type="password" class="regular-text" id="mcp_suite_' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off"></td></tr>';
	}

	public function handle_save(): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'wp-mcp-suite' ) );
		}

		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wp-mcp-suite' ) );
		}

		$repository = new GraphCredentialsRepository();
		$existing   = $repository->get();

		$tenant_id        = isset( $_POST['tenant_id'] ) ? sanitize_text_field( wp_unslash( $_POST['tenant_id'] ) ) : '';
		$client_id        = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$drive_id         = isset( $_POST['drive_id'] ) ? sanitize_text_field( wp_unslash( $_POST['drive_id'] ) ) : '';
		$base_folder_path = isset( $_POST['base_folder_path'] ) ? sanitize_text_field( wp_unslash( $_POST['base_folder_path'] ) ) : '/MCP Suite Content Mirror';

		// An empty submitted secret means "keep the existing one" (the form
		// never round-trips the real secret back into the field) — it does
		// NOT mean "clear the secret." Clearing credentials is a separate,
		// explicit action, not a side effect of an empty password field.
		$client_secret_input = isset( $_POST['client_secret'] ) ? (string) wp_unslash( $_POST['client_secret'] ) : '';
		$client_secret       = '' !== $client_secret_input ? $client_secret_input : ( $existing->client_secret ?? '' );

		if ( '' === $tenant_id || '' === $client_id || '' === $client_secret || '' === $drive_id ) {
			wp_die( esc_html__( 'Tenant ID, Client ID, Client Secret and Drive ID are all required.', 'wp-mcp-suite' ) );
		}

		$repository->save( $tenant_id, $client_id, $client_secret, $drive_id, $base_folder_path );

		AuditLogger::log( AuditLogger::ACTION_EDIT, 'core', 'graph_credentials', null, true, array( 'event' => 'credentials_saved' ) );

		wp_safe_redirect( add_query_arg( array( 'page' => 'mcp-suite-settings', 'mcp_suite_graph_saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
