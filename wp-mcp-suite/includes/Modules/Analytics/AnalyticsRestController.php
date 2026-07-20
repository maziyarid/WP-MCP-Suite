<?php
/**
 * @package MCPSuite\Modules\Analytics
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Analytics;

use MCPSuite\Core\Capabilities;
use MCPSuite\Core\RestControllerBase;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AnalyticsRestController extends RestControllerBase {

	protected function required_capability(): string {
		return Capabilities::VIEW_ANALYTICS;
	}

	protected function routes(): array {
		return array(
			array(
				'route'   => '/analytics/pull-now',
				'methods' => 'POST',
				'handler' => 'handle_pull_now',
			),
		);
	}

	public function handle_pull_now( WP_REST_Request $request ): WP_REST_Response {
		( new \MCPSuite\Modules\AnalyticsModule() )->run_daily_pull( force: true );

		return $this->success( array( 'message' => __( 'GA4 pull triggered.', 'wp-mcp-suite' ) ) );
	}
}
