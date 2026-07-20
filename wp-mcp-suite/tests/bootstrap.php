<?php
/**
 * PHPUnit bootstrap.
 *
 * The `unit` suite (tests/Unit) exercises pure-logic classes only — no
 * WordPress function is called, so no WordPress installation is required to
 * run it. This is intentional: it is the tier of tests that runs in a
 * plain CI job in seconds.
 *
 * The `integration` suite (tests/integration) exercises code that touches
 * $wpdb, hooks, or capabilities and therefore requires a real WordPress
 * test environment (the wordpress-develop test suite + a MySQL database),
 * bootstrapped the standard way:
 *   https://make.wordpress.org/cli/handbook/misc/plugin-unit-tests/
 * That bootstrap is intentionally NOT wired up here, because faking it
 * would let integration tests silently pass against nothing. Wire in
 * WP_TESTS_DIR before running `composer test:integration`.
 *
 * @package MCPSuite\Tests
 */

declare( strict_types = 1 );

if ( ! defined( 'MCP_SUITE_TESTING' ) ) {
	define( 'MCP_SUITE_TESTING', true );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$is_integration_run = in_array( '--testsuite', $_SERVER['argv'] ?? array(), true )
	&& in_array( 'integration', $_SERVER['argv'] ?? array(), true );

if ( ! $is_integration_run ) {
	require_once __DIR__ . '/Unit/wp-function-stubs.php';
}

if ( $is_integration_run ) {
	$wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

	if ( ! is_readable( $wp_tests_dir . '/includes/functions.php' ) ) {
		fwrite( STDERR, "Integration suite requires a WordPress test install. Set WP_TESTS_DIR and see https://make.wordpress.org/cli/handbook/misc/plugin-unit-tests/\n" );
		exit( 1 );
	}

	require_once $wp_tests_dir . '/includes/functions.php';
	require_once $wp_tests_dir . '/includes/bootstrap.php';
}
