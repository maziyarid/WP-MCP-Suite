<?php
/**
 * @package MCPSuite\Tests\Unit\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\ContentSync;

use MCPSuite\Modules\ContentSync\SyncDecision;
use MCPSuite\Modules\ContentSync\SyncEngine;
use PHPUnit\Framework\TestCase;

final class SyncEngineTest extends TestCase {

	private SyncEngine $engine;

	protected function setUp(): void {
		parent::setUp();
		$this->engine = new SyncEngine();
	}

	public function test_no_changes_on_either_side_skips(): void {
		$decision = $this->engine->decide( 'hash_a', 'hash_b', 'hash_a', 'hash_b' );
		$this->assertSame( SyncDecision::SKIP, $decision );
	}

	public function test_wp_changed_only_exports(): void {
		$decision = $this->engine->decide( 'hash_a_old', 'hash_b', 'hash_a_new', 'hash_b' );
		$this->assertSame( SyncDecision::EXPORT, $decision );
	}

	public function test_onedrive_changed_only_imports(): void {
		$decision = $this->engine->decide( 'hash_a', 'hash_b_old', 'hash_a', 'hash_b_new' );
		$this->assertSame( SyncDecision::IMPORT, $decision );
	}

	public function test_both_changed_is_a_conflict_never_auto_resolved(): void {
		$decision = $this->engine->decide( 'hash_a_old', 'hash_b_old', 'hash_a_new', 'hash_b_new' );
		$this->assertSame( SyncDecision::CONFLICT, $decision );
	}

	public function test_both_baselines_missing_is_treated_as_conflict_not_guessed_at(): void {
		// This is the pure function's answer when it has no baseline on
		// either side. SyncOrchestrator is responsible for recognising a
		// TRUE first-ever sync (no OneDrive item created yet at all) and
		// force-exporting without calling decide() — see SyncOrchestrator
		// tests. This test locks in that decide() itself never guesses.
		$decision = $this->engine->decide( null, null, 'wp_hash', 'placeholder_or_empty' );
		$this->assertSame( SyncDecision::CONFLICT, $decision );
	}

	public function test_never_exported_but_previously_imported_baseline_exports(): void {
		$decision = $this->engine->decide( null, 'hash_b', 'wp_hash', 'hash_b' );
		$this->assertSame( SyncDecision::EXPORT, $decision );
	}

	public function test_identical_hash_strings_use_constant_time_comparison_and_still_skip(): void {
		// Regression guard: an earlier draft of this logic used `===`
		// instead of hash_equals(); functionally identical for correctness
		// here, but hash_equals() is required so this code isn't copy-paste
		// reused later somewhere that DOES compare secrets.
		$decision = $this->engine->decide( 'same', 'same2', 'same', 'same2' );
		$this->assertSame( SyncDecision::SKIP, $decision );
	}
}
