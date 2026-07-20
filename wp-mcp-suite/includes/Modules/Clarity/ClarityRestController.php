<?php
/**
 * @package MCPSuite\Modules\Clarity
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Clarity;

use MCPSuite\Core\Capabilities;
use MCPSuite\Core\RestControllerBase;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ClarityRestController extends RestControllerBase {

	protected function required_capability(): string {
		return Capabilities::VIEW_ANALYTICS;
	}

	protected function routes(): array {
		return array(
			array(
				'route'   => '/clarity/pull-now',
				'methods' => 'POST',
				'handler' => 'handle_pull_now',
			),
		);
	}

	public function handle_pull_now( WP_REST_Request $request ): WP_REST_Response {
		( new \MCPSuite\Modules\ClarityModule() )->run_daily_pull( force: true );

		return $this->success( array( 'message' => __( 'Clarity pull triggered. Remember this counts against the 10-requests/day project quota.', 'wp-mcp-suite' ) ) );
	}
}
