<?php
/**
 * Version-tracked schema migrator.
 *
 * Spec requirement: "The plugin should create custom tables, not rely only
 * on postmeta." This runner tracks an installed schema version in options
 * and applies pending migrations via dbDelta, which is the only WordPress-
 * sanctioned way to create/alter tables idempotently.
 *
 * @package MCPSuite\Database
 */

declare( strict_types = 1 );

namespace MCPSuite\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Migrator {

	public const DB_VERSION_OPTION = 'mcp_suite_db_version';

	/**
	 * Ordered list of migration classes. Each must implement
	 * MigrationInterface. New migrations are appended, never inserted or
	 * reordered, so version history stays a straight line.
	 *
	 * @var class-string<MigrationInterface>[]
	 */
	private const MIGRATIONS = array(
		Migrations\Migration_1_0_0::class,
	);

	public static function current_version(): string {
		return (string) get_option( self::DB_VERSION_OPTION, '0.0.0' );
	}

	/**
	 * Runs every migration whose version is greater than the currently
	 * installed version, in order, and advances the stored version after
	 * each successful step. Safe to call on every plugin load — dbDelta
	 * itself is idempotent, and this loop skips already-applied versions.
	 */
	public static function migrate_to_latest(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$installed = self::current_version();

		foreach ( self::MIGRATIONS as $migration_class ) {
			/** @var MigrationInterface $migration */
			$migration = new $migration_class();

			if ( version_compare( $migration->version(), $installed, '<=' ) ) {
				continue;
			}

			$migration->up();

			update_option( self::DB_VERSION_OPTION, $migration->version() );
			$installed = $migration->version();
		}
	}

	public static function latest_available_version(): string {
		$latest = '0.0.0';

		foreach ( self::MIGRATIONS as $migration_class ) {
			/** @var MigrationInterface $migration */
			$migration = new $migration_class();
			if ( version_compare( $migration->version(), $latest, '>' ) ) {
				$latest = $migration->version();
			}
		}

		return $latest;
	}
}
