<?php
/**
 * Storage/retrieval for the Google service-account credentials this plugin
 * uses for Search Console and GA4. One service account is shared by both
 * integrations — the site owner creates it once in Google Cloud, then adds
 * its client_email as a user on both the Search Console property (with
 * "Full" or "Restricted" access under Settings → Users and permissions)
 * and the GA4 property (as a Viewer under Admin → Property Access
 * Management). No OAuth consent screen or refresh-token flow is needed —
 * this is the equivalent headless-friendly pattern to Graph's application
 * permissions, and was chosen for the same reason: cron jobs have no
 * signed-in user to act on behalf of.
 *
 * Only private_key_pem is encrypted — client_email is an identifier, not a
 * secret.
 *
 * @package MCPSuite\Core\Google
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Google;

use MCPSuite\Core\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GoogleCredentialsRepository {

	private const OPTION_KEY = 'mcp_suite_google_credentials';

	public function get(): ?GoogleServiceAccountCredentials {
		$stored = get_option( self::OPTION_KEY, null );

		if ( ! is_array( $stored ) || empty( $stored['client_email'] ) || empty( $stored['private_key_encrypted'] ) ) {
			return null;
		}

		try {
			$private_key = Encryption::decrypt( (string) $stored['private_key_encrypted'] );
		} catch ( \Throwable $e ) {
			return null;
		}

		return new GoogleServiceAccountCredentials(
			client_email: (string) $stored['client_email'],
			private_key_pem: $private_key
		);
	}

	public function save( string $client_email, string $private_key_pem ): bool {
		return update_option(
			self::OPTION_KEY,
			array(
				'client_email'           => sanitize_email( $client_email ),
				'private_key_encrypted'  => Encryption::encrypt( $private_key_pem ),
			)
		);
	}

	public function is_configured(): bool {
		return null !== $this->get();
	}
}
