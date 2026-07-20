<?php
/**
 * @package MCPSuite\Modules\Reporting
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Reporting;

use MCPSuite\Core\Capabilities;
use MCPSuite\Core\RestControllerBase;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReportingRestController extends RestControllerBase {

	protected function required_capability(): string {
		return Capabilities::VIEW_ANALYTICS;
	}

	protected function routes(): array {
		return array(
			array( 'route' => '/reporting/rebuild-now', 'methods' => 'POST', 'handler' => 'handle_rebuild_now' ),
		);
	}

	public function handle_rebuild_now( WP_REST_Request $request ): WP_REST_Response {
		( new \MCPSuite\Modules\ReportingModule() )->run_report_rebuild();
		return $this->success( array( 'message' => __( 'Report rebuild triggered.', 'wp-mcp-suite' ) ) );
	}
}
