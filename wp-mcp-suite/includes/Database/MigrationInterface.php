<?php
/**
 * Contract every schema migration must implement.
 *
 * @package MCPSuite\Database
 */

declare( strict_types = 1 );

namespace MCPSuite\Database;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

interface MigrationInterface {

	/**
	 * Semantic version this migration brings the schema to, e.g. '1.0.0'.
	 */
	public function version(): string;

	/**
	 * Applies the migration. Must be safe to call multiple times
	 * (dbDelta-based implementations get this for free).
	 */
	public function up(): void;

	/**
	 * @return array<string,string> Map of unprefixed table name => the raw
	 *                               dbDelta-compatible CREATE TABLE SQL for
	 *                               that table. Exposed separately from up()
	 *                               so the SQL shape can be unit tested
	 *                               without a live WordPress database.
	 */
	public function table_definitions(): array;
}
