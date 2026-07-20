<?php
/**
 * Registers the wp-admin menu. Every submenu page is gated on the exact
 * capability its module declares (spec: "Role-based access control for all
 * admin pages") — WordPress itself refuses to render the page for a user
 * lacking that capability, before render_admin_page() is ever called.
 *
 * @package MCPSuite\Admin
 */

declare( strict_types = 1 );

namespace MCPSuite\Admin;

use MCPSuite\Core\AuditLogger;
use MCPSuite\Core\Capabilities;
use MCPSuite\Core\Encryption;
use MCPSuite\Core\FeatureFlags;
use MCPSuite\Modules\ModuleInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminMenu {

	/** @var ModuleInterface[] */
	private array $modules;

	/**
	 * @param ModuleInterface[] $modules
	 */
	public function __construct( array $modules ) {
		$this->modules = $modules;
	}

	public function register(): void {
		add_menu_page(
			__( 'MCP Suite', 'wp-mcp-suite' ),
			__( 'MCP Suite', 'wp-mcp-suite' ),
			Capabilities::VIEW_DASHBOARD,
			'mcp-suite',
			array( $this, 'render_dashboard' ),
			'dashicons-networking',
			58
		);

		foreach ( $this->modules as $module ) {
			add_submenu_page(
				'mcp-suite',
				$module->label(),
				$module->label(),
				$module->required_capability(),
				'mcp-suite-' . $module->key(),
				array( $module, 'render_admin_page' )
			);
		}

		add_submenu_page(
			'mcp-suite',
			__( 'Settings', 'wp-mcp-suite' ),
			__( 'Settings', 'wp-mcp-suite' ),
			Capabilities::MANAGE_SETTINGS,
			'mcp-suite-settings',
			array( $this, 'render_settings' )
		);

		add_submenu_page(
			'mcp-suite',
			__( 'Audit Log', 'wp-mcp-suite' ),
			__( 'Audit Log', 'wp-mcp-suite' ),
			Capabilities::VIEW_AUDIT_LOG,
			'mcp-suite-audit-log',
			array( $this, 'render_audit_log' )
		);
	}

	public function render_dashboard(): void {
		if ( ! current_user_can( Capabilities::VIEW_DASHBOARD ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-mcp-suite' ) );
		}

		AuditLogger::log( AuditLogger::ACTION_VIEW, 'core', 'dashboard' );

		echo '<div class="wrap mcp-suite-dashboard">';
		echo '<h1>' . esc_html__( 'WP MCP Suite', 'wp-mcp-suite' ) . '</h1>';
		echo '<p>' . esc_html__( 'Phase 1 foundation is installed: data model, security core, and module scaffolding. Enable modules below as each phase ships.', 'wp-mcp-suite' ) . '</p>';

		echo '<table class="widefat striped" style="max-width:720px">';
		echo '<thead><tr><th>' . esc_html__( 'Module', 'wp-mcp-suite' ) . '</th><th>' . esc_html__( 'Status', 'wp-mcp-suite' ) . '</th></tr></thead><tbody>';

		foreach ( $this->modules as $module ) {
			$enabled = FeatureFlags::is_enabled( $module->key() );
			echo '<tr><td>' . esc_html( $module->label() ) . '</td><td>' .
				( $enabled
					? '<span style="color:#1a7f37">' . esc_html__( 'Enabled', 'wp-mcp-suite' ) . '</span>'
					: '<span style="color:#666">' . esc_html__( 'Not yet enabled', 'wp-mcp-suite' ) . '</span>'
				) . '</td></tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	public function render_settings(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-mcp-suite' ) );
		}

		AuditLogger::log( AuditLogger::ACTION_VIEW, 'core', 'settings' );

		echo '<div class="wrap"><h1>' . esc_html__( 'MCP Suite Settings', 'wp-mcp-suite' ) . '</h1>';

		if ( isset( $_GET['mcp_suite_flags_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Module settings saved.', 'wp-mcp-suite' ) . '</p></div>';
		}

		if ( ! Encryption::has_key() ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'No encryption key is configured yet. Add MCP_SUITE_ENCRYPTION_KEY to wp-config.php before enabling any module that stores API secrets.', 'wp-mcp-suite' ) .
				'</p></div>';
		}

		( new FeatureFlagsController( $this->modules ) )->render();

		( new GraphSettingsController() )->render();

		( new GoogleSettingsController() )->render();

		( new ClaritySettingsController() )->render();

		( new AiProviderSettingsController() )->render();

		( new BackupSettingsController() )->render();

		echo '</div>';
	}

	public function render_audit_log(): void {
		if ( ! current_user_can( Capabilities::VIEW_AUDIT_LOG ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-mcp-suite' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mcp_audit_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT event_time, actor_type, action, object_type, module, result FROM {$table} ORDER BY id DESC LIMIT 50" );

		echo '<div class="wrap"><h1>' . esc_html__( 'Audit Log', 'wp-mcp-suite' ) . '</h1>';
		$headings = array(
			__( 'Time', 'wp-mcp-suite' ),
			__( 'Actor', 'wp-mcp-suite' ),
			__( 'Action', 'wp-mcp-suite' ),
			__( 'Object', 'wp-mcp-suite' ),
			__( 'Module', 'wp-mcp-suite' ),
			__( 'Result', 'wp-mcp-suite' ),
		);

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( $headings as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No audit events recorded yet.', 'wp-mcp-suite' ) . '</td></tr>';
		} else {
			foreach ( $rows as $row ) {
				echo '<tr>' .
					'<td>' . esc_html( $row->event_time ) . '</td>' .
					'<td>' . esc_html( $row->actor_type ) . '</td>' .
					'<td>' . esc_html( $row->action ) . '</td>' .
					'<td>' . esc_html( (string) $row->object_type ) . '</td>' .
					'<td>' . esc_html( (string) $row->module ) . '</td>' .
					'<td>' . esc_html( $row->result ) . '</td>' .
					'</tr>';
			}
		}

		echo '</tbody></table></div>';
	}
}
