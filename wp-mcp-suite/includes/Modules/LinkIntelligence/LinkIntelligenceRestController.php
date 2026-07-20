<?php
/**
 * @package MCPSuite\Modules\LinkIntelligence
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\LinkIntelligence;

use MCPSuite\Core\Capabilities;
use MCPSuite\Core\RestControllerBase;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LinkIntelligenceRestController extends RestControllerBase {

	protected function required_capability(): string {
		return Capabilities::VIEW_ANALYTICS;
	}

	protected function routes(): array {
		return array(
			array(
				'route'   => '/link-intelligence/crawl-now',
				'methods' => 'POST',
				'handler' => 'handle_crawl_now',
			),
		);
	}

	public function handle_crawl_now( WP_REST_Request $request ): WP_REST_Response {
		( new \MCPSuite\Modules\LinkIntelligenceModule() )->run_internal_link_batch();

		return $this->success( array( 'message' => __( 'One internal-link crawl batch was processed.', 'wp-mcp-suite' ) ) );
	}
}
