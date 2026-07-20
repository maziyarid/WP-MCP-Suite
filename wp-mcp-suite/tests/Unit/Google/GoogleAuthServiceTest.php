<?php
/**
 * @package MCPSuite\Tests\Unit\Google
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\Google;

use MCPSuite\Core\Google\GoogleAuthException;
use MCPSuite\Core\Google\GoogleAuthService;
use MCPSuite\Core\Google\GoogleServiceAccountCredentials;
use MCPSuite\Core\Http\HttpResponse;
use MCPSuite\Tests\Unit\Http\Fakes\FakeHttpClient;
use MCPSuite\Tests\Unit\OAuth\Fakes\InMemoryTokenCache;
use PHPUnit\Framework\TestCase;

final class GoogleAuthServiceTest extends TestCase {

	private static string $private_key_pem;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		$resource = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
		openssl_pkey_export( $resource, $pem );
		self::$private_key_pem = $pem;
	}

	private function credentials(): GoogleServiceAccountCredentials {
		return new GoogleServiceAccountCredentials(
			client_email: 'mcp-suite@contoso-project.iam.gserviceaccount.com',
			private_key_pem: self::$private_key_pem
		);
	}

	private function success_response( int $expires_in = 3599 ): HttpResponse {
		return new HttpResponse( 200, json_encode( array( 'access_token' => 'ya29.fake-token', 'expires_in' => $expires_in, 'token_type' => 'Bearer' ) ) );
	}

	public function test_fetches_a_new_token_when_cache_is_empty(): void {
		$http  = new FakeHttpClient( $this->success_response() );
		$cache = new InMemoryTokenCache();
		$auth  = new GoogleAuthService( $http, $cache, $this->credentials(), 'https://www.googleapis.com/auth/webmasters.readonly', clock: static fn () => 1000 );

		$token = $auth->get_access_token();

		$this->assertSame( 'ya29.fake-token', $token );
		$this->assertSame( 1, $http->request_count() );
	}

	public function test_reuses_cached_token(): void {
		$http  = new FakeHttpClient( $this->success_response() );
		$cache = new InMemoryTokenCache();
		$auth  = new GoogleAuthService( $http, $cache, $this->credentials(), 'scope', clock: static fn () => 1000 );

		$auth->get_access_token();
		$auth->get_access_token();

		$this->assertSame( 1, $http->request_count() );
	}

	public function test_assertion_is_a_validly_signed_jwt_with_the_requested_scope(): void {
		$http  = new FakeHttpClient( $this->success_response() );
		$cache = new InMemoryTokenCache();
		$auth  = new GoogleAuthService( $http, $cache, $this->credentials(), 'https://www.googleapis.com/auth/analytics.readonly', clock: static fn () => 1000 );

		$auth->get_access_token();

		parse_str( $http->recorded_requests[0]['body'], $parsed );
		$this->assertSame( 'urn:ietf:params:oauth:grant-type:jwt-bearer', $parsed['grant_type'] );

		[$header_b64, $payload_b64] = explode( '.', $parsed['assertion'] );
		$payload = json_decode( base64_decode( strtr( $payload_b64, '-_', '+/' ) ), true );

		$this->assertSame( 'mcp-suite@contoso-project.iam.gserviceaccount.com', $payload['iss'] );
		$this->assertSame( 'https://www.googleapis.com/auth/analytics.readonly', $payload['scope'] );
		$this->assertSame( 'https://oauth2.googleapis.com/token', $payload['aud'] );
		$this->assertSame( 1000, $payload['iat'] );
		$this->assertSame( 4600, $payload['exp'] );
	}

	public function test_search_console_and_ga4_instances_cache_independently(): void {
		// Two separate GoogleAuthService instances (as ContentSync would
		// construct for GSC vs GA4) with two separate cache instances must
		// not clobber each other's cached token.
		$http_gsc  = new FakeHttpClient( new HttpResponse( 200, json_encode( array( 'access_token' => 'gsc-token', 'expires_in' => 3600 ) ) ) );
		$http_ga4  = new FakeHttpClient( new HttpResponse( 200, json_encode( array( 'access_token' => 'ga4-token', 'expires_in' => 3600 ) ) ) );
		$cache_gsc = new InMemoryTokenCache();
		$cache_ga4 = new InMemoryTokenCache();

		$gsc_auth = new GoogleAuthService( $http_gsc, $cache_gsc, $this->credentials(), 'webmasters.readonly', clock: static fn () => 1000 );
		$ga4_auth = new GoogleAuthService( $http_ga4, $cache_ga4, $this->credentials(), 'analytics.readonly', clock: static fn () => 1000 );

		$this->assertSame( 'gsc-token', $gsc_auth->get_access_token() );
		$this->assertSame( 'ga4-token', $ga4_auth->get_access_token() );
	}

	public function test_http_error_raises_google_auth_exception(): void {
		$http  = new FakeHttpClient( new HttpResponse( 401, json_encode( array( 'error' => 'invalid_grant' ) ) ) );
		$cache = new InMemoryTokenCache();
		$auth  = new GoogleAuthService( $http, $cache, $this->credentials(), 'scope', clock: static fn () => 1000 );

		try {
			$auth->get_access_token();
			$this->fail( 'Expected GoogleAuthException was not thrown.' );
		} catch ( GoogleAuthException $e ) {
			$this->assertSame( 401, $e->http_status() );
		}
	}

	public function test_malformed_private_key_raises_google_auth_exception_not_a_raw_openssl_warning(): void {
		$bad_credentials = new GoogleServiceAccountCredentials( 'test@example.com', 'not a real key' );
		$http             = new FakeHttpClient();
		$cache            = new InMemoryTokenCache();
		$auth             = new GoogleAuthService( $http, $cache, $bad_credentials, 'scope', clock: static fn () => 1000 );

		$this->expectException( GoogleAuthException::class );
		$auth->get_access_token();
	}

	public function test_expiry_buffer_forces_refetch(): void {
		$http = new FakeHttpClient(
			new HttpResponse( 200, json_encode( array( 'access_token' => 'first', 'expires_in' => 3600 ) ) ),
			new HttpResponse( 200, json_encode( array( 'access_token' => 'second', 'expires_in' => 3600 ) ) )
		);
		$cache = new InMemoryTokenCache();

		$now  = 1000;
		$auth = new GoogleAuthService( $http, $cache, $this->credentials(), 'scope', clock: function () use ( &$now ) { return $now; } );

		$this->assertSame( 'first', $auth->get_access_token() );

		$now = 4550; // inside the 120s safety buffer before real expiry at 4600
		$this->assertSame( 'second', $auth->get_access_token() );
	}
}
