<?php
/**
 * Produces a full-database SQL dump using $wpdb directly, rather than
 * shelling out to mysqldump — many WordPress hosts (especially the
 * shared/managed hosting this plugin's target sites commonly run on)
 * disable shell_exec entirely, so a PHP-native dumper is the only
 * approach that works everywhere. This is the same fundamental technique
 * every portable WordPress backup plugin uses.
 *
 * Yields SQL as a generator, table by table and in row batches within
 * each table, so a large site's dump is never fully materialized in
 * memory before compression — BackupModule streams this into the
 * archiver rather than building one giant string upfront.
 *
 * @package MCPSuite\Modules\Backup
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Backup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DatabaseDumper {

	private const ROW_BATCH_SIZE = 500;

	/**
	 * @return string[] Every table belonging to this WordPress install (matching $wpdb->prefix), not the whole database if the DB is shared with other applications.
	 */
	public function get_tables(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );

		return $tables ?: array();
	}

	/**
	 * @return \Generator<string> Yields SQL statements (schema + data) for every table.
	 */
	public function dump(): \Generator {
		global $wpdb;

		yield "-- WP MCP Suite database dump\n-- Generated: " . current_time( 'mysql', true ) . "\n-- Site: " . home_url() . "\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";

		foreach ( $this->get_tables() as $table ) {
			yield $this->dump_table_schema( $table );

			foreach ( $this->dump_table_data( $table ) as $insert_statement ) {
				yield $insert_statement;
			}
		}

		yield "\nSET FOREIGN_KEY_CHECKS=1;\n";
	}

	private function dump_table_schema( string $table ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$create = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
		$create_sql = $create[1] ?? '';

		return "\n-- --------------------------------------------------------\n\nDROP TABLE IF EXISTS `{$table}`;\n{$create_sql};\n\n";
	}

	/**
	 * @return \Generator<string>
	 */
	private function dump_table_data( string $table ): \Generator {
		global $wpdb;

		$offset = 0;

		while ( true ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM `{$table}` LIMIT %d OFFSET %d", self::ROW_BATCH_SIZE, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);

			if ( empty( $rows ) ) {
				return;
			}

			foreach ( $rows as $row ) {
				yield $this->row_to_insert_statement( $table, $row );
			}

			$offset += self::ROW_BATCH_SIZE;
		}
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function row_to_insert_statement( string $table, array $row ): string {
		global $wpdb;

		$columns = array_map( static fn( $col ) => "`{$col}`", array_keys( $row ) );
		$values  = array_map( fn( $value ) => $this->format_sql_value( $value ), array_values( $row ) );

		return "INSERT INTO `{$table}` (" . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ");\n";
	}

	private function format_sql_value( mixed $value ): string {
		global $wpdb;

		if ( null === $value ) {
			return 'NULL';
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		// $wpdb->_real_escape() applies the connection's actual charset-aware
		// escaping (mysqli_real_escape_string under the hood) rather than a
		// generic addslashes(), which matters for multi-byte content — this
		// plugin's real dumps are full of Persian/Farsi text.
		return "'" . $wpdb->_real_escape( (string) $value ) . "'";
	}
}
