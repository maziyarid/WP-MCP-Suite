<?php
/**
 * @package MCPSuite\Tests\Unit
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit;

use MCPSuite\Core\Encryption;
use PHPUnit\Framework\TestCase;

final class EncryptionTest extends TestCase {

	public function test_encrypt_then_decrypt_returns_original_plaintext(): void {
		$key       = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$plaintext = 'sk_live_example_secret_token_value';

		$ciphertext = Encryption::encrypt_with_key( $plaintext, $key );
		$decrypted  = Encryption::decrypt_with_key( $ciphertext, $key );

		$this->assertSame( $plaintext, $decrypted );
	}

	public function test_ciphertext_is_not_the_plaintext_and_is_base64(): void {
		$key       = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$plaintext = 'super-secret-client-secret';

		$ciphertext = Encryption::encrypt_with_key( $plaintext, $key );

		$this->assertNotSame( $plaintext, $ciphertext );
		$this->assertNotFalse( base64_decode( $ciphertext, true ) );
	}

	public function test_two_encryptions_of_same_plaintext_produce_different_ciphertext(): void {
		// Verifies a fresh random nonce is used every call (no ECB-style
		// determinism, which would leak whether two secrets are identical).
		$key       = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$plaintext = 'identical-secret';

		$a = Encryption::encrypt_with_key( $plaintext, $key );
		$b = Encryption::encrypt_with_key( $plaintext, $key );

		$this->assertNotSame( $a, $b );
	}

	public function test_decrypt_with_wrong_key_throws(): void {
		$key_a = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$key_b = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );

		$ciphertext = Encryption::encrypt_with_key( 'value', $key_a );

		$this->expectException( \RuntimeException::class );
		Encryption::decrypt_with_key( $ciphertext, $key_b );
	}

	public function test_decrypt_with_tampered_ciphertext_throws(): void {
		$key        = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$ciphertext = Encryption::encrypt_with_key( 'value', $key );

		$raw            = base64_decode( $ciphertext, true );
		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 0xFF ); // flip last byte
		$tampered       = base64_encode( $raw );

		$this->expectException( \RuntimeException::class );
		Encryption::decrypt_with_key( $tampered, $key );
	}

	public function test_encrypt_rejects_wrong_key_length(): void {
		$this->expectException( \InvalidArgumentException::class );
		Encryption::encrypt_with_key( 'value', 'too-short-key' );
	}

	public function test_generate_key_produces_correct_length_when_decoded(): void {
		$key = Encryption::generate_key();
		$raw = base64_decode( $key, true );

		$this->assertNotFalse( $raw );
		$this->assertSame( SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen( $raw ) );
	}
}
