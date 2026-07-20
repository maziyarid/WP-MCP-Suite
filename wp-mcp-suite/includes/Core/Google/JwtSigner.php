<?php
/**
 * Builds and signs a JWT assertion for Google's OAuth2 service-account
 * flow (RFC 7523 JWT Bearer grant). Uses PHP's built-in openssl extension
 * for RS256 signing — no external JWT library dependency, unlike the DOCX
 * conversion path in Content Sync, which means this class (unlike
 * PhpWordDocxConverter) can be fully exercised in any PHP 8.1+ environment,
 * including this build sandbox, with a real generated keypair.
 *
 * @package MCPSuite\Core\Google
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Google;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class JwtSigner {

	/**
	 * @param array<string,mixed> $claims
	 *
	 * @throws JwtSigningException If the private key is malformed or signing fails.
	 */
	public function sign( array $claims, string $private_key_pem ): string {
		$header = array( 'alg' => 'RS256', 'typ' => 'JWT' );

		$segments = array(
			$this->base64url_encode( (string) json_encode( $header, JSON_UNESCAPED_SLASHES ) ),
			$this->base64url_encode( (string) json_encode( $claims, JSON_UNESCAPED_SLASHES ) ),
		);

		$signing_input = implode( '.', $segments );

		$private_key = openssl_pkey_get_private( $private_key_pem );

		if ( false === $private_key ) {
			throw new JwtSigningException( 'The provided private key could not be parsed. Check that the full PEM block (including BEGIN/END lines) was stored correctly.' );
		}

		$signature = '';
		$signed    = openssl_sign( $signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256 );

		if ( ! $signed || '' === $signature ) {
			throw new JwtSigningException( 'openssl_sign() failed to produce a signature.' );
		}

		$segments[] = $this->base64url_encode( $signature );

		return implode( '.', $segments );
	}

	private function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}
}
