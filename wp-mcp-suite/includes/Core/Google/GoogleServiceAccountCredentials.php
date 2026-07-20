<?php
/**
 * @package MCPSuite\Core\Google
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Google;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class GoogleServiceAccountCredentials {

	public function __construct(
		public readonly string $client_email,
		public readonly string $private_key_pem
	) {}
}
