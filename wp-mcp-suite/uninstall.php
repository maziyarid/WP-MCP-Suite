<?php
/**
 * Uninstall handler.
 *
 * WordPress only executes this file when a user deletes the plugin from the
 * Plugins screen (not on deactivation). Per spec §"Security and medical
 * constraints", data destruction must never be silent or implicit.
 *
 * Default behaviour: KEEP all custom tables, options and audit logs.
 * Data is only removed if the site owner explicitly enabled
 * "mcp_suite_purge_on_uninstall" from the plugin's Settings screen first.
 * The audit log itself is never deleted by this routine, even when purge is
 * enabled, so there is always a record that the plugin (and its data) existed
 * and who requested removal.
 *
 * @package MCPSuite
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$purge_enabled = get_option( 'mcp_suite_purge_on_uninstall', false );

if ( ! $purge_enabled ) {
	// Safe default: leave every table and option in place so the site owner
	// can reinstall the plugin later without any data loss.
	return;
}

// Tables that are safe to drop when the owner explicitly opted in.
// wp_mcp_audit_log is intentionally excluded — audit history must survive
// plugin removal for compliance / forensic purposes.
$mcp_droppable_tables = array(
	'mcp_content_map',
	'mcp_docx_versions',
	'mcp_sync_log',
	'mcp_seo_snapshots',
	'mcp_gsc_history',
	'mcp_ga_history',
	'mcp_clarity_history',
	'mcp_uptime_events',
	'mcp_internal_links',
	'mcp_backlinks',
	'mcp_ai_runs',
	'mcp_backup_jobs',
);

foreach ( $mcp_droppable_tables as $table ) {
	// Table names cannot be parameterised; they are drawn exclusively from
	// the hardcoded allow-list above, never from user input.
	$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

$mcp_option_keys = array(
	'mcp_suite_db_version',
	'mcp_suite_feature_flags',
	'mcp_suite_purge_on_uninstall',
	'mcp_suite_encryption_key_ref',
);

foreach ( $mcp_option_keys as $option_key ) {
	delete_option( $option_key );
	delete_site_option( $option_key );
}
