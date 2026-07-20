<?php
/**
 * Renders and handles the module enable/disable toggles on the Settings
 * screen — the human-facing side of FeatureFlags. Kept as a separate
 * controller from GraphSettingsController so each form save is a single,
 * clearly-scoped POST handler rather than one large do-everything form.
 *
 * @package MCPSuite\Admin
 */

declare( strict_types = 1 );

namespace MCPSuite\Admin;

use MCPSuite\Core\AuditLogger;
use MCPSuite\Core\Capabilities;
use MCPSuite\Core\FeatureFlags;
use MCPSuite\Modules\ModuleInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FeatureFlagsController {

	private const NONCE_ACTION = 'mcp_suite_save_feature_flags';
	private const NONCE_FIELD  = 'mcp_suite_flags_nonce';

	/** @var ModuleInterface[] */
	private array $modules;

	/**
	 * @param ModuleInterface[] $modules
	 */
	public function __construct( array $modules ) {
		$this->modules = $modules;
	}

	public function register(): void {
		add_action( 'admin_post_mcp_suite_save_feature_flags', array( $this, 'handle_save' ) );
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Modules', 'wp-mcp-suite' ) . '</h2>';
		echo '<p>' . esc_html__( 'Every module ships disabled by default. Enable a module only once its prerequisites (e.g. Graph credentials for Content Sync) are configured — the module\'s own admin page will tell you if something is still missing.', 'wp-mcp-suite' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<input type="hidden" name="action" value="mcp_suite_save_feature_flags">';

		echo '<table class="form-table"><tbody>';
		foreach ( $this->modules as $module ) {
			$checked = FeatureFlags::is_enabled( $module->key() ) ? 'checked' : '';
			echo '<tr><th scope="row">' . esc_html( $module->label() ) . '</th><td>' .
				'<label><input type="checkbox" name="modules[]" value="' . esc_attr( $module->key() ) . '" ' . esc_attr( $checked ) . '> ' .
				esc_html__( 'Enabled', 'wp-mcp-suite' ) . '</label></td></tr>';
		}
		echo '</tbody></table>';

		submit_button( __( 'Save Module Settings', 'wp-mcp-suite' ) );
		echo '</form>';
	}

	public function handle_save(): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'wp-mcp-suite' ) );
		}

		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wp-mcp-suite' ) );
		}

		$submitted = isset( $_POST['modules'] ) && is_array( $_POST['modules'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['modules'] ) )
			: array();

		foreach ( FeatureFlags::module_keys() as $module_key ) {
			FeatureFlags::set( $module_key, in_array( $module_key, $submitted, true ) );
		}

		AuditLogger::log( AuditLogger::ACTION_EDIT, 'core', 'feature_flags', null, true, array( 'event' => 'flags_saved', 'enabled' => implode( ',', $submitted ) ) );

		wp_safe_redirect( add_query_arg( array( 'page' => 'mcp-suite-settings', 'mcp_suite_flags_saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
