<?php
/**
 * Acquires and caches Microsoft Graph access tokens via the OAuth 2.0
 * client-credentials grant (application permissions — see
 * GraphCredentialsRepository for why delegated auth was not used).
 *
 * Scope minimization: client-credentials tokens always request
 * "https://graph.microsoft.com/.default" — for app-only auth, Graph does
 * not accept a hand-picked scope list per request; the actual permission
 * set is whatever an Azure AD admin granted consent for on the app
 * registration. Minimization therefore happens at app-registration time
 * (grant only Files.ReadWrite.All / Sites.ReadWrite.All, nothing broader),
 * not in this class — this class cannot request less than what the app
 * was consented for, and must not be changed to request more.
 *
 * Every collaborator is injected, so the full token-acquisition-with-
 * caching logic is unit testable with a fake HTTP client, a fake cache,
 * and a fake clock — no live network call, no WordPress transient API.
 *
 * @package MCPSuite\Core\Graph
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Graph;

use MCPSuite\Core\OAuth\CachedToken;
use MCPSuite\Core\OAuth\TokenCacheInterface;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class GraphAuthService {

	private const REQUIRED_SCOPE = 'https://graph.microsoft.com/.default';

	/** @var callable():int */
	private $clock;

	/**
	 * @param (callable():int)|null $clock Returns the current unix timestamp. Defaults to time().
	 */
	public function __construct(
		private readonly GraphHttpClientInterface $http_client,
		private readonly TokenCacheInterface $token_cache,
		private readonly GraphCredentials $credentials,
		?callable $clock = null
	) {
		$this->clock = $clock ?? static fn(): int => time();
	}

	/**
	 * @throws GraphException On any auth failure — callers must not treat
	 *                        a missing token as "skip silently"; every
	 *                        caller in this plugin catches this and writes
	 *                        a failed wp_mcp_sync_log row.
	 */
	public function get_access_token(): string {
		$now = ( $this->clock )();

		$cached = $this->token_cache->get();
		if ( null !== $cached && $cached->is_valid_at( $now ) ) {
			return $cached->access_token;
		}

		$token = $this->request_new_token();
		$this->token_cache->set( $token );

		return $token->access_token;
	}

	/**
	 * Forces a fresh token on the next call, e.g. after a 401 from Graph
	 * that suggests the cached token was revoked.
	 */
	public function invalidate(): void {
		$this->token_cache->clear();
	}

	private function request_new_token(): CachedToken {
		$url = sprintf(
			'https://login.microsoftonline.com/%s/oauth2/v2.0/token',
			rawurlencode( $this->credentials->tenant_id )
		);

		$body = http_build_query(
			array(
				'client_id'     => $this->credentials->client_id,
				'client_secret' => $this->credentials->client_secret,
				'scope'         => self::REQUIRED_SCOPE,
				'grant_type'    => 'client_credentials',
			)
		);

		$response = $this->http_client->request(
			'POST',
			$url,
			array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
			$body
		);

		if ( ! $response->is_success() ) {
			throw new GraphException(
				'Microsoft Graph token request failed.',
				$response->status,
				$response->graph_error_code()
			);
		}

		$json = $response->json();

		if ( empty( $json['access_token'] ) || empty( $json['expires_in'] ) ) {
			throw new GraphException(
				'Microsoft Graph token response was missing access_token or expires_in.',
				$response->status,
				null
			);
		}

		$now = ( $this->clock )();

		return new CachedToken(
			(string) $json['access_token'],
			$now + (int) $json['expires_in']
		);
	}
}
