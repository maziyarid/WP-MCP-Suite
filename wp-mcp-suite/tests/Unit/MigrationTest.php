<?php
/**
 * @package MCPSuite\Tests\Unit
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit;

use MCPSuite\Database\Migrations\Migration_1_0_0;
use PHPUnit\Framework\TestCase;

final class MigrationTest extends TestCase {

	private const EXPECTED_TABLES = array(
		'mcp_content_map',
		'mcp_docx_versions',
		'mcp_sync_log',
		'mcp_seo_snapshots',
		'mcp_gsc_history',
		'mcp_ga_history',
		'mcp_clarity_history',
		'mcp_uptime_events',
		'mcp_internal_links',
		'mcp_backlinks',
		'mcp_ai_runs',
		'mcp_backup_jobs',
		'mcp_audit_log',
	);

	public function test_defines_exactly_the_thirteen_tables_from_spec(): void {
		$migration = new Migration_1_0_0();
		$tables    = $migration->table_definitions();

		$this->assertCount( 13, $tables );
		$this->assertSame( self::EXPECTED_TABLES, array_keys( $tables ) );
	}

	public function test_every_table_has_a_primary_key(): void {
		$migration = new Migration_1_0_0();

		foreach ( $migration->table_definitions() as $table => $sql ) {
			$this->assertStringContainsString( 'PRIMARY KEY', $sql, "{$table} is missing a PRIMARY KEY" );
		}
	}

	public function test_every_table_uses_the_configured_charset_collate(): void {
		$migration = new Migration_1_0_0();

		foreach ( $migration->table_definitions() as $table => $sql ) {
			$this->assertStringContainsString( 'utf8mb4', $sql, "{$table} does not apply the site charset/collation" );
		}
	}

	public function test_every_table_name_is_prefixed_from_wpdb(): void {
		$migration = new Migration_1_0_0();

		foreach ( $migration->table_definitions() as $table => $sql ) {
			$this->assertStringContainsString( "CREATE TABLE wptests_{$table}", $sql );
		}
	}

	public function test_audit_log_has_no_columns_that_would_defeat_append_only_use(): void {
		// Structural smoke test: confirms the audit table was not given a
		// soft-delete/update-style column, which would invite code to
		// treat audit rows as editable instead of append-only.
		$migration = new Migration_1_0_0();
		$sql       = $migration->table_definitions()['mcp_audit_log'];

		$this->assertStringNotContainsString( 'deleted_at', $sql );
		$this->assertStringNotContainsString( 'updated_at', $sql );
	}

	public function test_version_string_is_semver(): void {
		$migration = new Migration_1_0_0();
		$this->assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', $migration->version() );
	}
}
