<?php
/**
 * @package MCPSuite\Core\Graph
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\OAuth;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

interface TokenCacheInterface {

	public function get(): ?CachedToken;

	public function set( CachedToken $token ): void;

	public function clear(): void;
}
