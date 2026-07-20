<?php
/**
 * @package MCPSuite\Tests\Unit
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit;

use MCPSuite\Core\FeatureFlags;
use PHPUnit\Framework\TestCase;

final class FeatureFlagsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Reset the in-memory options store between tests.
		$GLOBALS['mcp_suite_test_options'] = array();
	}

	public function test_every_module_is_disabled_by_default_secure_by_default(): void {
		foreach ( FeatureFlags::all() as $module => $enabled ) {
			$this->assertFalse( $enabled, "{$module} should default to disabled" );
		}
	}

	public function test_set_and_is_enabled_round_trip(): void {
		FeatureFlags::set( FeatureFlags::MODULE_SEO, true );

		$this->assertTrue( FeatureFlags::is_enabled( FeatureFlags::MODULE_SEO ) );
		$this->assertFalse( FeatureFlags::is_enabled( FeatureFlags::MODULE_BACKUP ) );
	}

	public function test_set_rejects_unknown_module_key(): void {
		$result = FeatureFlags::set( 'not_a_real_module', true );

		$this->assertFalse( $result );
		$this->assertFalse( FeatureFlags::is_enabled( 'not_a_real_module' ) );
	}

	public function test_all_always_returns_every_known_module_even_if_option_is_stale(): void {
		// Simulate an old install whose stored option predates a module
		// that was added later — all() must still report it (as disabled),
		// never omit it or fatal.
		update_option( FeatureFlags::OPTION_KEY, array( 'content_sync' => true ) );

		$all = FeatureFlags::all();

		$this->assertSame( FeatureFlags::module_keys(), array_keys( $all ) );
		$this->assertTrue( $all[ FeatureFlags::MODULE_CONTENT_SYNC ] );
		$this->assertFalse( $all[ FeatureFlags::MODULE_BACKUP ] );
	}

	public function test_seed_defaults_does_not_overwrite_existing_option(): void {
		FeatureFlags::set( FeatureFlags::MODULE_ANALYTICS, true );
		FeatureFlags::seed_defaults();

		$this->assertTrue( FeatureFlags::is_enabled( FeatureFlags::MODULE_ANALYTICS ) );
	}
}
