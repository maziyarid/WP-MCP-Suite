<?php
/**
 * Storage for Clarity credentials. Unlike Graph and Google, Clarity's
 * Data Export API uses a single long-lived JWT token generated manually by
 * a project admin in the Clarity dashboard (Settings → Data Export →
 * Generate new API token) — there is no OAuth exchange or refresh flow,
 * so no auth service is needed here, just the stored token used directly
 * as the Bearer credential. It is still encrypted at rest like any other
 * secret.
 *
 * @package MCPSuite\Modules\Clarity
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Clarity;

use MCPSuite\Core\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ClarityCredentialsRepository {

	private const OPTION_KEY = 'mcp_suite_clarity_credentials';

	public function get_token(): ?string {
		$stored = get_option( self::OPTION_KEY, null );

		if ( ! is_array( $stored ) || empty( $stored['token_encrypted'] ) ) {
			return null;
		}

		try {
			return Encryption::decrypt( (string) $stored['token_encrypted'] );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	public function save( string $token ): bool {
		return update_option(
			self::OPTION_KEY,
			array( 'token_encrypted' => Encryption::encrypt( $token ) )
		);
	}

	public function is_configured(): bool {
		return null !== $this->get_token();
	}
}
