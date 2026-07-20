<?php
/**
 * @package MCPSuite\Modules\Backup
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Backup;

use MCPSuite\Core\Capabilities;
use MCPSuite\Core\RestControllerBase;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class BackupRestController extends RestControllerBase {

	protected function required_capability(): string {
		return Capabilities::MANAGE_BACKUPS;
	}

	protected function routes(): array {
		return array(
			array( 'route' => '/backup/run-now', 'methods' => 'POST', 'handler' => 'handle_run_now' ),
			array( 'route' => '/backup/prune-now', 'methods' => 'POST', 'handler' => 'handle_prune_now' ),
		);
	}

	public function handle_run_now( WP_REST_Request $request ): WP_REST_Response {
		( new \MCPSuite\Modules\BackupModule() )->run_backup();
		return $this->success( array( 'message' => __( 'Backup run triggered. Check Backup History for the result.', 'wp-mcp-suite' ) ) );
	}

	public function handle_prune_now( WP_REST_Request $request ): WP_REST_Response {
		( new \MCPSuite\Modules\BackupModule() )->run_retention_prune();
		return $this->success( array( 'message' => __( 'Retention prune triggered.', 'wp-mcp-suite' ) ) );
	}
}
