<?php
/**
 * Renders and handles the Google service-account credentials and property
 * identifier forms on the Settings screen. One service account is shared
 * by Search Console and GA4 — see GoogleCredentialsRepository's docblock.
 *
 * @package MCPSuite\Admin
 */

declare( strict_types = 1 );

namespace MCPSuite\Admin;

use MCPSuite\Core\AuditLogger;
use MCPSuite\Core\Capabilities;
use MCPSuite\Core\Encryption;
use MCPSuite\Core\Google\GoogleCredentialsRepository;
use MCPSuite\Core\Google\GooglePropertiesRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GoogleSettingsController {

	private const NONCE_ACTION = 'mcp_suite_save_google_credentials';
	private const NONCE_FIELD  = 'mcp_suite_google_nonce';

	public function register(): void {
		add_action( 'admin_post_mcp_suite_save_google_credentials', array( $this, 'handle_save' ) );
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		if ( isset( $_GET['mcp_suite_google_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Google credentials saved.', 'wp-mcp-suite' ) . '</p></div>';
		}

		$credentials_repo = new GoogleCredentialsRepository();
		$properties_repo  = new GooglePropertiesRepository();
		$credentials      = $credentials_repo->get();

		echo '<h2>' . esc_html__( 'Google (Search Console + GA4)', 'wp-mcp-suite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Service-account auth — create a service account in Google Cloud Console, then add its email as a user on your Search Console property (Settings → Users and permissions) and as a Viewer on your GA4 property (Admin → Property Access Management). No OAuth consent screen is needed.', 'wp-mcp-suite' ) . '</p>';

		if ( ! Encryption::has_key() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'MCP_SUITE_ENCRYPTION_KEY is not set in wp-config.php. Credentials cannot be saved until it is configured — see the Content Sync section above.', 'wp-mcp-suite' ) . '</p></div>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<input type="hidden" name="action" value="mcp_suite_save_google_credentials">';

		echo '<table class="form-table"><tbody>';
		echo '<tr><th scope="row"><label for="mcp_suite_client_email">' . esc_html__( 'Service Account Email', 'wp-mcp-suite' ) . '</label></th><td>' .
			'<input type="text" class="regular-text" id="mcp_suite_client_email" name="client_email" value="' . esc_attr( $credentials->client_email ?? '' ) . '" placeholder="mcp-suite@my-project.iam.gserviceaccount.com"></td></tr>';

		$key_placeholder = $credentials ? __( 'Leave blank to keep the current private key', 'wp-mcp-suite' ) : '';
		echo '<tr><th scope="row"><label for="mcp_suite_private_key">' . esc_html__( 'Private Key (PEM)', 'wp-mcp-suite' ) . '</label></th><td>' .
			'<textarea class="large-text code" rows="8" id="mcp_suite_private_key" name="private_key" placeholder="' . esc_attr( $key_placeholder ) . '" autocomplete="off"></textarea>' .
			'<p class="description">' . esc_html__( 'The "private_key" field from the service account JSON key file, including the BEGIN/END PRIVATE KEY lines.', 'wp-mcp-suite' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="mcp_suite_gsc_site_url">' . esc_html__( 'Search Console Site URL', 'wp-mcp-suite' ) . '</label></th><td>' .
			'<input type="text" class="regular-text" id="mcp_suite_gsc_site_url" name="gsc_site_url" value="' . esc_attr( $properties_repo->get_gsc_site_url() ) . '" placeholder="https://drbastaninejad.com/ or sc-domain:drbastaninejad.com"></td></tr>';

		echo '<tr><th scope="row"><label for="mcp_suite_ga4_property_id">' . esc_html__( 'GA4 Property ID', 'wp-mcp-suite' ) . '</label></th><td>' .
			'<input type="text" class="regular-text" id="mcp_suite_ga4_property_id" name="ga4_property_id" value="' . esc_attr( $properties_repo->get_ga4_property_id() ) . '" placeholder="123456789"></td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'Save Google Settings', 'wp-mcp-suite' ) );
		echo '</form>';
	}

	public function handle_save(): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'wp-mcp-suite' ) );
		}

		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wp-mcp-suite' ) );
		}

		$credentials_repo = new GoogleCredentialsRepository();
		$existing          = $credentials_repo->get();

		$client_email = isset( $_POST['client_email'] ) ? sanitize_email( wp_unslash( $_POST['client_email'] ) ) : '';
		$gsc_site_url = isset( $_POST['gsc_site_url'] ) ? sanitize_text_field( wp_unslash( $_POST['gsc_site_url'] ) ) : '';
		$ga4_property = isset( $_POST['ga4_property_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ga4_property_id'] ) ) : '';

		// Same "blank means keep existing" rule as the Graph credentials form.
		$private_key_input = isset( $_POST['private_key'] ) ? (string) wp_unslash( $_POST['private_key'] ) : '';
		$private_key        = '' !== trim( $private_key_input ) ? $private_key_input : ( $existing->private_key_pem ?? '' );

		if ( '' === $client_email || '' === $private_key ) {
			wp_die( esc_html__( 'Service Account Email and Private Key are required.', 'wp-mcp-suite' ) );
		}

		$credentials_repo->save( $client_email, $private_key );
		( new GooglePropertiesRepository() )->save( $gsc_site_url, $ga4_property );

		AuditLogger::log( AuditLogger::ACTION_EDIT, 'core', 'google_credentials', null, true, array( 'event' => 'credentials_saved' ) );

		wp_safe_redirect( add_query_arg( array( 'page' => 'mcp-suite-settings', 'mcp_suite_google_saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
