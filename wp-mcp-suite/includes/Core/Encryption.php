<?php
/**
 * Encryption helper for secrets stored at rest (API secrets, OAuth tokens,
 * backup encryption keys).
 *
 * Design rule (fixes the exact issue flagged in the source audit — a
 * hardcoded Cloudflare Turnstile secret key in theme code): the encryption
 * key itself must NEVER live in the database next to the ciphertext it
 * protects. It must be defined as a constant in wp-config.php (outside the
 * web root's document tree on most hosts) or injected via server
 * environment variable. This class refuses to encrypt/decrypt if no such
 * key is configured — it does not silently fall back to a database-stored
 * key, because that would reintroduce the exact vulnerability being fixed.
 *
 * The pure cryptographic operations (encrypt_with_key / decrypt_with_key)
 * take the key as an explicit argument and touch no WordPress functions, so
 * they can be unit tested in isolation without a WordPress environment.
 *
 * @package MCPSuite\Core
 */

declare( strict_types = 1 );

namespace MCPSuite\Core;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class Encryption {

	public const KEY_CONSTANT = 'MCP_SUITE_ENCRYPTION_KEY';

	/**
	 * Thrown (as RuntimeException) when the site owner has not configured
	 * MCP_SUITE_ENCRYPTION_KEY in wp-config.php yet. Modules that need
	 * encrypted storage must catch this and show an actionable admin
	 * notice rather than let a fatal error surface to a site visitor.
	 */
	public static function has_key(): bool {
		return defined( self::KEY_CONSTANT )
			&& is_string( constant( self::KEY_CONSTANT ) )
			&& '' !== constant( self::KEY_CONSTANT );
	}

	/**
	 * Generates a new random key suitable for the wp-config.php constant.
	 * This is a setup-time convenience only — the plugin never calls this
	 * automatically and never persists the result itself. The admin UI
	 * displays it once so the site owner can copy it into wp-config.php.
	 */
	public static function generate_key(): string {
		return base64_encode( random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
	}

	/**
	 * @throws \RuntimeException When no encryption key is configured.
	 */
	public static function get_key(): string {
		if ( ! self::has_key() ) {
			throw new \RuntimeException(
				'MCP_SUITE_ENCRYPTION_KEY is not defined in wp-config.php. ' .
				'Secrets cannot be stored until this is configured.'
			);
		}

		$key = base64_decode( (string) constant( self::KEY_CONSTANT ), true );

		if ( false === $key || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) ) {
			throw new \RuntimeException(
				'MCP_SUITE_ENCRYPTION_KEY is malformed. It must be a base64-encoded ' .
				SODIUM_CRYPTO_SECRETBOX_KEYBYTES . '-byte key, e.g. the output of ' .
				'Encryption::generate_key().'
			);
		}

		return $key;
	}

	public static function encrypt( string $plaintext ): string {
		return self::encrypt_with_key( $plaintext, self::get_key() );
	}

	public static function decrypt( string $ciphertext_b64 ): string {
		return self::decrypt_with_key( $ciphertext_b64, self::get_key() );
	}

	/**
	 * Pure function: no WordPress or global state. Safe to unit test.
	 */
	public static function encrypt_with_key( string $plaintext, string $key ): string {
		if ( SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) ) {
			throw new \InvalidArgumentException( 'Encryption key must be exactly ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' bytes.' );
		}

		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );

		// Store as nonce||ciphertext, base64-encoded, so it is a single
		// opaque string safe to place in a TEXT column.
		return base64_encode( $nonce . $ciphertext );
	}

	/**
	 * Pure function: no WordPress or global state. Safe to unit test.
	 *
	 * @throws \InvalidArgumentException On malformed input.
	 * @throws \RuntimeException         On authentication failure (tampered ciphertext or wrong key).
	 */
	public static function decrypt_with_key( string $ciphertext_b64, string $key ): string {
		if ( SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) ) {
			throw new \InvalidArgumentException( 'Encryption key must be exactly ' . SODIUM_CRYPTO_SECRETBOX_KEYBYTES . ' bytes.' );
		}

		$raw = base64_decode( $ciphertext_b64, true );
		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			throw new \InvalidArgumentException( 'Ciphertext is not validly base64-encoded or is too short.' );
		}

		$nonce      = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );

		if ( false === $plaintext ) {
			throw new \RuntimeException( 'Decryption failed: ciphertext is invalid, tampered with, or the key is wrong.' );
		}

		return $plaintext;
	}
}
