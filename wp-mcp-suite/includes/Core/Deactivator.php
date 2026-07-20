<?php
/**
 * Runs on plugin deactivation.
 *
 * Deactivation must be non-destructive and reversible: clear scheduled
 * cron hooks so nothing keeps firing in the background, but leave every
 * table, option and audit record untouched. Data removal only ever happens
 * through uninstall.php, and only when explicitly opted in — see that file.
 *
 * @package MCPSuite\Core
 */

declare( strict_types = 1 );

namespace MCPSuite\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Deactivator {

	/**
	 * Cron hook names for every module's scheduled job, per spec
	 * "Cronjobs to implement". Centralised here so activation/deactivation
	 * and each module always agree on the hook name.
	 *
	 * @var string[]
	 */
	public const CRON_HOOKS = array(
		'mcp_suite_cron_health_check',
		'mcp_suite_cron_uptime_fetch',
		'mcp_suite_cron_content_sync',
		'mcp_suite_cron_ai_queue_check',
		'mcp_suite_cron_gsc_pull',
		'mcp_suite_cron_ga_pull',
		'mcp_suite_cron_clarity_pull',
		'mcp_suite_cron_link_graph_rebuild',
		'mcp_suite_cron_seo_snapshot',
		'mcp_suite_cron_backlink_refresh',
		'mcp_suite_cron_backup_weekly',
		'mcp_suite_cron_report_rebuild',
		'mcp_suite_cron_archive_prune',
	);

	public static function deactivate(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		foreach ( self::CRON_HOOKS as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			while ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
				$timestamp = wp_next_scheduled( $hook );
			}
		}

		AuditLogger::log(
			AuditLogger::ACTION_EDIT,
			'core',
			'plugin',
			null,
			true,
			array( 'event' => 'deactivated', 'version' => MCP_SUITE_VERSION )
		);
	}
}
