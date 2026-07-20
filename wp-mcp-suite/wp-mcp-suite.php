<?php
/**
 * Plugin Name:       WP MCP Suite
 * Plugin URI:        https://teznevise.ir/
 * Description:       Modular content sync, SEO, analytics, reporting, backup and Microsoft 365
 *                     integration platform for WordPress. Built for medical / YMYL sites requiring
 *                     access control, audit logging and encrypted configuration.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Teznevise
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-mcp-suite
 * Domain Path:       /languages
 *
 * @package MCPSuite
 */

declare( strict_types = 1 );

// Hard block: this file must only ever be loaded through WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Negative requirement (see spec §7): the plugin must fail closed, not open.
// If PHP or WP minimums are not met, the plugin must refuse to bootstrap
// rather than run in a partially-broken state.
// ---------------------------------------------------------------------------
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'WP MCP Suite requires PHP 8.1 or higher. The plugin has not been activated.', 'wp-mcp-suite' ) .
				'</p></div>';
		}
	);
	return;
}

if ( ! extension_loaded( 'sodium' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'WP MCP Suite requires the PHP sodium extension for secret encryption and will not load without it.', 'wp-mcp-suite' ) .
				'</p></div>';
		}
	);
	return;
}

define( 'MCP_SUITE_VERSION', '1.0.0' );
define( 'MCP_SUITE_FILE', __FILE__ );
define( 'MCP_SUITE_DIR', plugin_dir_path( __FILE__ ) );
define( 'MCP_SUITE_URL', plugin_dir_url( __FILE__ ) );
define( 'MCP_SUITE_DB_VERSION', '1.0.0' );

// Composer autoloader (PSR-4: MCPSuite\ => includes/).
$mcp_suite_autoloader = MCP_SUITE_DIR . 'vendor/autoload.php';
if ( is_readable( $mcp_suite_autoloader ) ) {
	require_once $mcp_suite_autoloader;
} else {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'WP MCP Suite: dependencies are missing. Run "composer install --no-dev" in the plugin directory before activating.', 'wp-mcp-suite' ) .
				'</p></div>';
		}
	);
	return;
}

use MCPSuite\Core\Activator;
use MCPSuite\Core\Deactivator;
use MCPSuite\Core\Plugin;

register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );

// uninstall.php (not this file) is responsible for destructive cleanup, and
// only runs when the site owner has explicitly opted in via settings.

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	}
);
