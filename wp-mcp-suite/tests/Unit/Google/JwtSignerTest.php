<?php
/**
 * @package MCPSuite\Tests\Unit\Google
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\Google;

use MCPSuite\Core\Google\JwtSigner;
use MCPSuite\Core\Google\JwtSigningException;
use PHPUnit\Framework\TestCase;

final class JwtSignerTest extends TestCase {

	private static string $private_key_pem;
	private static string $public_key_pem;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		// A real, freshly generated 2048-bit RSA keypair — the same shape
		// Google issues in a service-account JSON key file. Generated once
		// per test run, not committed anywhere.
		$resource = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			)
		);

		openssl_pkey_export( $resource, $private_key_pem );
		self::$private_key_pem = $private_key_pem;

		$details              = openssl_pkey_get_details( $resource );
		self::$public_key_pem = $details['key'];
	}

	private function base64url_decode( string $data ): string {
		return (string) base64_decode( strtr( $data, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $data ) % 4 ) % 4 ), true );
	}

	public function test_produces_a_three_segment_jwt(): void {
		$signer = new JwtSigner();
		$jwt    = $signer->sign( array( 'iss' => 'test@example.iam.gserviceaccount.com' ), self::$private_key_pem );

		$this->assertSame( 3, substr_count( $jwt, '.' ) + 1 );
	}

	public function test_header_and_payload_round_trip_correctly(): void {
		$signer = new JwtSigner();
		$claims = array(
			'iss'   => 'test@example.iam.gserviceaccount.com',
			'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
			'aud'   => 'https://oauth2.googleapis.com/token',
			'iat'   => 1000,
			'exp'   => 4600,
		);

		$jwt = $signer->sign( $claims, self::$private_key_pem );

		[$header_b64, $payload_b64] = explode( '.', $jwt );

		$header  = json_decode( $this->base64url_decode( $header_b64 ), true );
		$payload = json_decode( $this->base64url_decode( $payload_b64 ), true );

		$this->assertSame( 'RS256', $header['alg'] );
		$this->assertSame( 'JWT', $header['typ'] );
		$this->assertSame( $claims, $payload );
	}

	public function test_signature_verifies_against_the_matching_public_key(): void {
		$signer = new JwtSigner();
		$jwt    = $signer->sign( array( 'iss' => 'test@example.iam.gserviceaccount.com', 'iat' => 1000 ), self::$private_key_pem );

		[$header_b64, $payload_b64, $signature_b64] = explode( '.', $jwt );

		$signing_input = $header_b64 . '.' . $payload_b64;
		$signature     = $this->base64url_decode( $signature_b64 );

		$result = openssl_verify( $signing_input, $signature, self::$public_key_pem, OPENSSL_ALGO_SHA256 );

		$this->assertSame( 1, $result, 'signature must verify against the corresponding public key' );
	}

	public function test_signature_does_not_verify_against_a_different_keypair(): void {
		$other_resource = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
		$other_details   = openssl_pkey_get_details( $other_resource );

		$signer = new JwtSigner();
		$jwt    = $signer->sign( array( 'iss' => 'test' ), self::$private_key_pem );

		[$header_b64, $payload_b64, $signature_b64] = explode( '.', $jwt );
		$signing_input = $header_b64 . '.' . $payload_b64;
		$signature     = $this->base64url_decode( $signature_b64 );

		$result = openssl_verify( $signing_input, $signature, $other_details['key'], OPENSSL_ALGO_SHA256 );

		$this->assertSame( 0, $result, 'signature must not verify against an unrelated public key' );
	}

	public function test_malformed_private_key_throws_a_clear_exception(): void {
		$signer = new JwtSigner();

		$this->expectException( JwtSigningException::class );
		$signer->sign( array( 'iss' => 'test' ), 'not a real PEM key' );
	}

	public function test_base64url_encoding_has_no_padding_or_url_unsafe_characters(): void {
		$signer = new JwtSigner();
		$jwt    = $signer->sign( array( 'data' => str_repeat( 'x', 50 ) ), self::$private_key_pem );

		$this->assertStringNotContainsString( '=', $jwt );
		$this->assertStringNotContainsString( '+', $jwt );
		$this->assertStringNotContainsString( '/', $jwt );
	}
}
