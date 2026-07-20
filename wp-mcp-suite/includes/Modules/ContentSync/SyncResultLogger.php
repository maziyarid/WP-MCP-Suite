<?php
/**
 * Writes a SyncResult to wp_mcp_sync_log and the audit log. Extracted so
 * the REST "sync now" handler and the cron batch handler log identically
 * rather than maintaining two copies of the same logic.
 *
 * @package MCPSuite\Modules\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\ContentSync;

use MCPSuite\Core\AuditLogger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SyncResultLogger {

	public function log( SyncResult $result ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mcp_sync_log',
			array(
				'job_id'      => wp_generate_uuid4(),
				'module'      => 'content_sync',
				'object_type' => 'post',
				'object_id'   => $result->post_id,
				'direction'   => $result->decision->value,
				'status'      => $result->success ? 'success' : 'failed',
				'message'     => $result->message . ( $result->warnings ? ' | ' . implode( '; ', $result->warnings ) : '' ),
				'started_at'  => current_time( 'mysql', true ),
				'finished_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		AuditLogger::log(
			AuditLogger::ACTION_SYNC,
			'content_sync',
			'post',
			$result->post_id,
			$result->success,
			array( 'decision' => $result->decision->value, 'message' => $result->message )
		);
	}
}
