<?php
/**
 * @package MCPSuite\Tests\Unit\Graph
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\Graph;

use MCPSuite\Core\Graph\GraphAuthService;
use MCPSuite\Core\Graph\GraphCredentials;
use MCPSuite\Core\Graph\GraphException;
use MCPSuite\Core\Graph\GraphHttpResponse;
use MCPSuite\Tests\Unit\Graph\Fakes\FakeGraphHttpClient;
use MCPSuite\Tests\Unit\OAuth\Fakes\InMemoryTokenCache;
use PHPUnit\Framework\TestCase;

final class GraphAuthServiceTest extends TestCase {

	private function credentials(): GraphCredentials {
		return new GraphCredentials(
			tenant_id: 'contoso-tenant-id',
			client_id: 'contoso-client-id',
			client_secret: 'super-secret-value',
			drive_id: 'drive-123',
			base_folder_path: '/MCP Suite Content Mirror'
		);
	}

	private function success_token_response( int $expires_in = 3600 ): GraphHttpResponse {
		return new GraphHttpResponse(
			200,
			json_encode( array( 'access_token' => 'fresh-token-abc', 'expires_in' => $expires_in, 'token_type' => 'Bearer' ) )
		);
	}

	public function test_fetches_a_new_token_when_cache_is_empty(): void {
		$http  = new FakeGraphHttpClient( $this->success_token_response() );
		$cache = new InMemoryTokenCache();
		$auth  = new GraphAuthService( $http, $cache, $this->credentials(), static fn () => 1000 );

		$token = $auth->get_access_token();

		$this->assertSame( 'fresh-token-abc', $token );
		$this->assertSame( 1, $http->request_count() );
		$this->assertSame( 1, $cache->set_call_count );
	}

	public function test_reuses_cached_token_without_a_new_http_call(): void {
		$http  = new FakeGraphHttpClient( $this->success_token_response( 3600 ) );
		$cache = new InMemoryTokenCache();
		$now   = 1000;
		$auth  = new GraphAuthService( $http, $cache, $this->credentials(), static fn () => $now );

		$first  = $auth->get_access_token();
		$second = $auth->get_access_token();

		$this->assertSame( $first, $second );
		$this->assertSame( 1, $http->request_count(), 'second call should be served entirely from cache' );
	}

	public function test_refetches_once_the_expiry_safety_buffer_is_crossed(): void {
		$http = new FakeGraphHttpClient(
			$this->success_token_response( 3600 ), // first token, valid 1000..4600, buffer means "valid" until 4480
			$this->success_token_response( 3600 )
		);
		$cache = new InMemoryTokenCache();

		$clock_value = 1000;
		$clock       = function () use ( &$clock_value ) {
			return $clock_value;
		};

		$auth = new GraphAuthService( $http, $cache, $this->credentials(), $clock );

		$auth->get_access_token();
		$this->assertSame( 1, $http->request_count() );

		// Jump forward to inside the 120s safety buffer before real expiry.
		$clock_value = 4550;

		$auth->get_access_token();
		$this->assertSame( 2, $http->request_count(), 'token within the expiry safety buffer must be treated as unusable' );
	}

	public function test_invalidate_forces_a_fresh_token_on_next_call(): void {
		$http  = new FakeGraphHttpClient( $this->success_token_response(), $this->success_token_response() );
		$cache = new InMemoryTokenCache();
		$auth  = new GraphAuthService( $http, $cache, $this->credentials(), static fn () => 1000 );

		$auth->get_access_token();
		$auth->invalidate();
		$auth->get_access_token();

		$this->assertSame( 2, $http->request_count() );
	}

	public function test_token_request_uses_client_credentials_grant_and_default_scope(): void {
		$http  = new FakeGraphHttpClient( $this->success_token_response() );
		$cache = new InMemoryTokenCache();
		$auth  = new GraphAuthService( $http, $cache, $this->credentials(), static fn () => 1000 );

		$auth->get_access_token();

		$sent_body = $http->recorded_requests[0]['body'];
		parse_str( $sent_body, $parsed );

		$this->assertSame( 'client_credentials', $parsed['grant_type'] );
		$this->assertSame( 'https://graph.microsoft.com/.default', $parsed['scope'] );
		$this->assertSame( 'contoso-client-id', $parsed['client_id'] );
		$this->assertSame( 'super-secret-value', $parsed['client_secret'] );
	}

	public function test_http_error_status_raises_graph_exception_with_status_and_does_not_cache(): void {
		$http = new FakeGraphHttpClient(
			new GraphHttpResponse( 401, json_encode( array( 'error' => array( 'code' => 'InvalidClientSecret', 'message' => 'bad secret' ) ) ) )
		);
		$cache = new InMemoryTokenCache();
		$auth  = new GraphAuthService( $http, $cache, $this->credentials(), static fn () => 1000 );

		try {
			$auth->get_access_token();
			$this->fail( 'Expected GraphException was not thrown.' );
		} catch ( GraphException $e ) {
			$this->assertSame( 401, $e->http_status() );
			$this->assertSame( 'InvalidClientSecret', $e->graph_error_code() );
			$this->assertFalse( $e->is_retryable(), '401 is a config problem, not transient' );
		}

		$this->assertSame( 0, $cache->set_call_count );
	}

	public function test_rate_limit_status_is_marked_retryable(): void {
		$http = new FakeGraphHttpClient( new GraphHttpResponse( 429, '{}' ) );
		$cache = new InMemoryTokenCache();
		$auth  = new GraphAuthService( $http, $cache, $this->credentials(), static fn () => 1000 );

		try {
			$auth->get_access_token();
			$this->fail( 'Expected GraphException was not thrown.' );
		} catch ( GraphException $e ) {
			$this->assertTrue( $e->is_retryable() );
		}
	}

	public function test_malformed_success_response_missing_access_token_throws(): void {
		$http  = new FakeGraphHttpClient( new GraphHttpResponse( 200, json_encode( array( 'expires_in' => 3600 ) ) ) );
		$cache = new InMemoryTokenCache();
		$auth  = new GraphAuthService( $http, $cache, $this->credentials(), static fn () => 1000 );

		$this->expectException( GraphException::class );
		$auth->get_access_token();
	}
}
