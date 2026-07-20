<?php
/**
 * @package MCPSuite\Modules\SearchConsole
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\SearchConsole;

use MCPSuite\Core\Capabilities;
use MCPSuite\Core\RestControllerBase;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SearchConsoleRestController extends RestControllerBase {

	protected function required_capability(): string {
		return Capabilities::VIEW_ANALYTICS;
	}

	protected function routes(): array {
		return array(
			array(
				'route'   => '/search-console/pull-now',
				'methods' => 'POST',
				'handler' => 'handle_pull_now',
			),
		);
	}

	public function handle_pull_now( WP_REST_Request $request ): WP_REST_Response {
		( new \MCPSuite\Modules\SearchConsoleModule() )->run_daily_pull( force: true );

		return $this->success( array( 'message' => __( 'Search Console pull triggered.', 'wp-mcp-suite' ) ) );
	}
}
