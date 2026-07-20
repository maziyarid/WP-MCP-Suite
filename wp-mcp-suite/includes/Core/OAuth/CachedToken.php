<?php
/**
 * @package MCPSuite\Core\Graph
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\OAuth;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class CachedToken {

	public function __construct(
		public readonly string $access_token,
		public readonly int $expires_at_unix_timestamp
	) {}

	/**
	 * A token is considered usable until this many seconds before its real
	 * expiry, so a token never gets used for the start of a long-running
	 * request only to expire mid-flight.
	 */
	private const EXPIRY_SAFETY_BUFFER_SECONDS = 120;

	public function is_valid_at( int $now ): bool {
		return $now < ( $this->expires_at_unix_timestamp - self::EXPIRY_SAFETY_BUFFER_SECONDS );
	}
}
