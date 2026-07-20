<?php
/**
 * Abstraction over the HTTP transport used to call Microsoft Graph (and,
 * later, GSC/GA4/Clarity/Uptime Robot/AI providers can use the same
 * pattern). Every external HTTP call in this plugin goes through an
 * interface like this one — never a bare wp_remote_request() call inside
 * business logic — so the test suite can substitute a fake and never make
 * a live network call.
 *
 * @package MCPSuite\Core\Graph
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Graph;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

interface GraphHttpClientInterface {

	/**
	 * @param string               $method  GET|POST|PUT|PATCH|DELETE
	 * @param string               $url     Absolute URL.
	 * @param array<string,string> $headers
	 * @param string|null          $body    Raw request body, if any.
	 *
	 * @throws GraphException On transport-level failure (timeouts, DNS, etc).
	 *                        HTTP error status codes (4xx/5xx) are NOT
	 *                        thrown here — they come back as a normal
	 *                        GraphHttpResponse so callers can inspect the
	 *                        Graph error payload and decide how to react
	 *                        (retry, refresh token, surface to user).
	 */
	public function request( string $method, string $url, array $headers = array(), ?string $body = null ): GraphHttpResponse;
}
