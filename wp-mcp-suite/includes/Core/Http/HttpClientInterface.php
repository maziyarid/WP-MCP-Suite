<?php
/**
 * Generic HTTP transport abstraction, provider-agnostic. Every external
 * HTTP call in the plugin goes through an interface like this rather than
 * a bare wp_remote_request() call inside business logic, so tests can
 * substitute a fake and never make a live network call. Microsoft Graph
 * has its own parallel interface (Core\Graph\GraphHttpClientInterface)
 * predating this one; consolidating the two is scoped to the Phase 8
 * hardening pass rather than risking a mid-flight refactor of already-
 * verified Phase 2 code — see technical specification §11.
 *
 * @package MCPSuite\Core\Http
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Http;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

interface HttpClientInterface {

	/**
	 * @param string               $method  GET|POST|PUT|PATCH|DELETE
	 * @param string               $url     Absolute URL.
	 * @param array<string,string> $headers
	 * @param string|null          $body    Raw request body, if any.
	 *
	 * @throws HttpException On transport-level failure (timeouts, DNS,
	 *                       etc). HTTP error status codes (4xx/5xx) are
	 *                       NOT thrown here — they come back as a normal
	 *                       HttpResponse so callers can inspect the body
	 *                       and decide how to react.
	 */
	public function request( string $method, string $url, array $headers = array(), ?string $body = null ): HttpResponse;
}
