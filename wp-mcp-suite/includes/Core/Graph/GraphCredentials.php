<?php
/**
 * @package MCPSuite\Core\Graph
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Graph;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class GraphCredentials {

	public function __construct(
		public readonly string $tenant_id,
		public readonly string $client_id,
		public readonly string $client_secret,
		public readonly string $drive_id,
		public readonly string $base_folder_path
	) {}
}
