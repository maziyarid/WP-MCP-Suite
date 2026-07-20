<?php
/**
 * Acquires and caches Google OAuth2 access tokens via the JWT Bearer grant
 * (RFC 7523) for service-account auth. One instance is scoped to exactly
 * one OAuth scope (e.g. webmasters.readonly OR analytics.readonly) — Search
 * Console and GA4 each construct their own GoogleAuthService with their own
 * scope and their own token cache key, even though they may share the same
 * underlying credentials.
 *
 * Structurally mirrors Core\Graph\GraphAuthService (cache-check, fetch,
 * cache-store, invalidate) using the generic Core\Http and Core\OAuth
 * layers instead of Graph-specific ones — this is the shared pattern the
 * Phase 8 hardening pass should retrofit Graph onto, not a second pattern.
 *
 * @package MCPSuite\Core\Google
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Google;

use MCPSuite\Core\Http\HttpClientInterface;
use MCPSuite\Core\Http\HttpException;
use MCPSuite\Core\OAuth\CachedToken;
use MCPSuite\Core\OAuth\TokenCacheInterface;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class GoogleAuthService {

	private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

	/** @var callable():int */
	private $clock;

	/**
	 * @param (callable():int)|null $clock Returns the current unix timestamp. Defaults to time().
	 */
	public function __construct(
		private readonly HttpClientInterface $http_client,
		private readonly TokenCacheInterface $token_cache,
		private readonly GoogleServiceAccountCredentials $credentials,
		private readonly string $scope,
		private readonly JwtSigner $jwt_signer = new JwtSigner(),
		?callable $clock = null
	) {
		$this->clock = $clock ?? static fn(): int => time();
	}

	/**
	 * @throws GoogleAuthException On any auth failure.
	 */
	public function get_access_token(): string {
		$now = ( $this->clock )();

		$cached = $this->token_cache->get();
		if ( null !== $cached && $cached->is_valid_at( $now ) ) {
			return $cached->access_token;
		}

		$token = $this->request_new_token( $now );
		$this->token_cache->set( $token );

		return $token->access_token;
	}

	public function invalidate(): void {
		$this->token_cache->clear();
	}

	private function request_new_token( int $now ): CachedToken {
		// Google requires exp - iat <= 3600 seconds for a JWT bearer assertion.
		$assertion_ttl = 3600;

		try {
			$jwt = $this->jwt_signer->sign(
				array(
					'iss'   => $this->credentials->client_email,
					'scope' => $this->scope,
					'aud'   => self::TOKEN_ENDPOINT,
					'iat'   => $now,
					'exp'   => $now + $assertion_ttl,
				),
				$this->credentials->private_key_pem
			);
		} catch ( JwtSigningException $e ) {
			throw new GoogleAuthException( 'Failed to sign the Google service-account JWT: ' . $e->getMessage(), null, $e );
		}

		$body = http_build_query(
			array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			)
		);

		try {
			$response = $this->http_client->request(
				'POST',
				self::TOKEN_ENDPOINT,
				array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
				$body
			);
		} catch ( HttpException $e ) {
			throw new GoogleAuthException( 'Google token request failed at the transport level: ' . $e->getMessage(), null, $e );
		}

		if ( ! $response->is_success() ) {
			throw new GoogleAuthException(
				'Google token request was rejected (HTTP ' . $response->status . '): ' . $response->body,
				$response->status
			);
		}

		$json = $response->json();

		if ( empty( $json['access_token'] ) || empty( $json['expires_in'] ) ) {
			throw new GoogleAuthException( 'Google token response was missing access_token or expires_in.', $response->status );
		}

		return new CachedToken( (string) $json['access_token'], $now + (int) $json['expires_in'] );
	}
}
