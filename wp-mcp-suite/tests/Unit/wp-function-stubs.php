<?php
/**
 * Minimal stand-in for $wpdb, plus a handful of WordPress function stubs
 * (get_option/update_option/esc_html/__ etc.), used exclusively by
 * tests/Unit so pure-ish logic classes can run without a WordPress
 * installation. This is NOT used by tests/integration, which runs against
 * the real WordPress test suite and the real functions.
 *
 * @package MCPSuite\Tests
 */

declare( strict_types = 1 );

final class MCP_Suite_Stub_Wpdb {

	public string $prefix = 'wptests_';

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
	}
}

$GLOBALS['wpdb'] = new MCP_Suite_Stub_Wpdb();

// ---------------------------------------------------------------------------
// Minimal WordPress function stubs, in-memory only, used exclusively by the
// unit suite so genuinely pure-ish classes (FeatureFlags) can be exercised
// without a database. Anything beyond simple options storage (hooks,
// capabilities, nonces, $wpdb queries) belongs in tests/integration against
// the real WordPress test suite instead of being stubbed here.
// ---------------------------------------------------------------------------
if ( ! function_exists( 'get_option' ) ) {
	$GLOBALS['mcp_suite_test_options'] = array();

	function get_option( string $key, $default = false ) {
		return $GLOBALS['mcp_suite_test_options'][ $key ] ?? $default;
	}

	function update_option( string $key, $value ): bool {
		$GLOBALS['mcp_suite_test_options'][ $key ] = $value;
		return true;
	}

	function add_option( string $key, $value ): bool {
		if ( array_key_exists( $key, $GLOBALS['mcp_suite_test_options'] ) ) {
			return false;
		}
		$GLOBALS['mcp_suite_test_options'][ $key ] = $value;
		return true;
	}

	function delete_option( string $key ): bool {
		unset( $GLOBALS['mcp_suite_test_options'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	// Faithful-enough stubs of WordPress's escaping/i18n functions for pure
	// unit tests. Real integration tests run against the genuine WordPress
	// functions instead — these exist only so classes that correctly follow
	// WPCS ("always escape output with esc_*, always wrap strings in __()")
	// don't have to be treated as WordPress-only just because of that.
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}

	function esc_url( string $url ): string {
		return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
	}

	function __( string $text, string $domain = 'default' ): string { // phpcs:ignore
		return $text;
	}

	function esc_html__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
