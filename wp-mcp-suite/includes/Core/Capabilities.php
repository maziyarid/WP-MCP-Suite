<?php
/**
 * Custom capability model.
 *
 * Spec requirement: "Role-based access control for all admin pages" and
 * "least-privilege access". No capability here is ever granted to the
 * default Administrator role implicitly by WordPress — they are granted
 * explicitly on activation and can be revoked/reassigned by a site owner
 * without touching code.
 *
 * @package MCPSuite\Core
 */

declare( strict_types = 1 );

namespace MCPSuite\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Capabilities {

	public const VIEW_DASHBOARD    = 'mcp_view_dashboard';
	public const MANAGE_CONTENT    = 'mcp_manage_content_sync';
	public const MANAGE_SEO        = 'mcp_manage_seo';
	public const VIEW_ANALYTICS    = 'mcp_view_analytics';
	public const MANAGE_AI_CONTENT = 'mcp_manage_ai_content';
	public const MANAGE_BACKUPS    = 'mcp_manage_backups';
	public const MANAGE_SETTINGS   = 'mcp_manage_settings';
	public const VIEW_AUDIT_LOG    = 'mcp_view_audit_log';

	/**
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::VIEW_DASHBOARD,
			self::MANAGE_CONTENT,
			self::MANAGE_SEO,
			self::VIEW_ANALYTICS,
			self::MANAGE_AI_CONTENT,
			self::MANAGE_BACKUPS,
			self::MANAGE_SETTINGS,
			self::VIEW_AUDIT_LOG,
		);
	}

	/**
	 * Grants every plugin capability to Administrator only, and creates a
	 * narrower "MCP Editor" role for staff who should be able to review
	 * content/SEO/analytics but never touch backups, settings or the audit
	 * log. Called once from Activator; safe to call repeatedly (idempotent).
	 */
	public static function install(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all() as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		add_role(
			'mcp_editor',
			__( 'MCP Content Editor', 'wp-mcp-suite' ),
			array(
				'read'                  => true,
				self::VIEW_DASHBOARD    => true,
				self::MANAGE_CONTENT    => true,
				self::MANAGE_SEO        => true,
				self::VIEW_ANALYTICS    => true,
				self::MANAGE_AI_CONTENT => true,
				// Deliberately absent: MANAGE_BACKUPS, MANAGE_SETTINGS,
				// VIEW_AUDIT_LOG. Those remain administrator-only.
			)
		);
	}

	/**
	 * Removes plugin capabilities and the custom role on uninstall-time
	 * cleanup. Never called on simple deactivation — see Deactivator.
	 */
	public static function remove(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::all() as $cap ) {
				$admin->remove_cap( $cap );
			}
		}

		remove_role( 'mcp_editor' );
	}
}
