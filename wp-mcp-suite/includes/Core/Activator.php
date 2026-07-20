<?php
/**
 * Runs on plugin activation.
 *
 * @package MCPSuite\Core
 */

declare( strict_types = 1 );

namespace MCPSuite\Core;

use MCPSuite\Database\Migrator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Activator {

	public static function activate(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		Migrator::migrate_to_latest();
		Capabilities::install();
		FeatureFlags::seed_defaults();

		add_option( 'mcp_suite_purge_on_uninstall', false );

		AuditLogger::log(
			AuditLogger::ACTION_EDIT,
			'core',
			'plugin',
			null,
			true,
			array( 'event' => 'activated', 'version' => MCP_SUITE_VERSION )
		);

		// Modules register their own cron schedules in their own boot(),
		// not here — activation only guarantees the data model and
		// permissions exist. See ModuleInterface::register().
	}
}
