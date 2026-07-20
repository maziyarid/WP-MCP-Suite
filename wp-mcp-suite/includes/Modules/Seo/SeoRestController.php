<?php
/**
 * @package MCPSuite\Modules\Seo
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Seo;

use MCPSuite\Core\Capabilities;
use MCPSuite\Core\RestControllerBase;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SeoRestController extends RestControllerBase {

	protected function required_capability(): string {
		return Capabilities::MANAGE_SEO;
	}

	protected function routes(): array {
		return array(
			array(
				'route'   => '/seo/run-now',
				'methods' => 'POST',
				'handler' => 'handle_run_now',
			),
		);
	}

	public function handle_run_now( WP_REST_Request $request ): WP_REST_Response {
		( new \MCPSuite\Modules\SeoModule() )->run_daily_snapshot_batch();

		return $this->success( array( 'message' => __( 'One SEO snapshot batch was processed.', 'wp-mcp-suite' ) ) );
	}
}
