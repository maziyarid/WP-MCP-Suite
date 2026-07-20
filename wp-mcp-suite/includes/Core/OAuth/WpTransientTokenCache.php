<?php
/**
 * Real token cache, generic across every OAuth-based integration in the
 * plugin (Microsoft Graph, Google service-account auth, and any future
 * one). Access tokens are short-lived but still bearer credentials, so
 * they are encrypted at rest with the same Encryption service as
 * long-lived secrets — consistent with this plugin's blanket "no plaintext
 * secrets in the database" rule rather than carving out an exception for
 * tokens because they happen to expire soon.
 *
 * Each caller supplies its own transient key (e.g. "mcp_suite_graph_token",
 * "mcp_suite_google_token") so Graph and Google tokens are cached
 * independently rather than overwriting each other.
 *
 * @package MCPSuite\Core\OAuth
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\OAuth;

use MCPSuite\Core\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WpTransientTokenCache implements TokenCacheInterface {

	public function __construct( private readonly string $transient_key ) {}

	public function get(): ?CachedToken {
		$stored = get_transient( $this->transient_key );

		if ( ! is_array( $stored ) || empty( $stored['token_encrypted'] ) || empty( $stored['expires_at'] ) ) {
			return null;
		}

		try {
			$access_token = Encryption::decrypt( (string) $stored['token_encrypted'] );
		} catch ( \Throwable $e ) {
			return null;
		}

		return new CachedToken( $access_token, (int) $stored['expires_at'] );
	}

	public function set( CachedToken $token ): void {
		set_transient(
			$this->transient_key,
			array(
				'token_encrypted' => Encryption::encrypt( $token->access_token ),
				'expires_at'      => $token->expires_at_unix_timestamp,
			),
			max( 60, $token->expires_at_unix_timestamp - time() )
		);
	}

	public function clear(): void {
		delete_transient( $this->transient_key );
	}
}
