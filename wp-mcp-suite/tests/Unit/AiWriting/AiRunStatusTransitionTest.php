<?php
/**
 * @package MCPSuite\Tests\Unit\AiWriting
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\AiWriting;

use MCPSuite\Modules\AiWriting\AiRunStatus;
use MCPSuite\Modules\AiWriting\AiRunStatusTransition;
use MCPSuite\Modules\AiWriting\InvalidStatusTransitionException;
use PHPUnit\Framework\TestCase;

final class AiRunStatusTransitionTest extends TestCase {

	private AiRunStatusTransition $transition;

	protected function setUp(): void {
		parent::setUp();
		$this->transition = new AiRunStatusTransition();
	}

	// --- The core mandatory-review-gate rule ---------------------------

	public function test_drafted_to_published_is_blocked_without_human_review(): void {
		$result = $this->transition->is_allowed( AiRunStatus::DRAFTED, AiRunStatus::PUBLISHED, false );
		$this->assertNotTrue( $result );
		$this->assertStringContainsString( 'human_reviewed', (string) $result );
	}

	public function test_drafted_to_published_is_allowed_with_human_review(): void {
		$this->assertTrue( $this->transition->is_allowed( AiRunStatus::DRAFTED, AiRunStatus::PUBLISHED, true ) );
	}

	public function test_edited_to_published_is_blocked_without_human_review(): void {
		$result = $this->transition->is_allowed( AiRunStatus::EDITED, AiRunStatus::PUBLISHED, false );
		$this->assertNotTrue( $result );
	}

	public function test_edited_to_published_is_allowed_with_human_review(): void {
		$this->assertTrue( $this->transition->is_allowed( AiRunStatus::EDITED, AiRunStatus::PUBLISHED, true ) );
	}

	public function test_assert_allowed_throws_for_a_blocked_publish_attempt(): void {
		$this->expectException( InvalidStatusTransitionException::class );
		$this->transition->assert_allowed( AiRunStatus::DRAFTED, AiRunStatus::PUBLISHED, false );
	}

	public function test_assert_allowed_does_not_throw_for_a_valid_reviewed_publish(): void {
		$this->transition->assert_allowed( AiRunStatus::DRAFTED, AiRunStatus::PUBLISHED, true );
		$this->addToAssertionCount( 1 ); // reaching here without an exception is the assertion
	}

	// --- Editing -----------------------------------------------------

	public function test_drafted_to_edited_is_always_allowed(): void {
		$this->assertTrue( $this->transition->is_allowed( AiRunStatus::DRAFTED, AiRunStatus::EDITED, false ) );
		$this->assertTrue( $this->transition->is_allowed( AiRunStatus::DRAFTED, AiRunStatus::EDITED, true ) );
	}

	public function test_edited_to_edited_again_is_not_a_meaningful_transition(): void {
		// Same-state "transition" (e.g. saving another round of edits)
		// isn't modeled as a status change at all — the caller just keeps
		// status=edited and updates content; this class only governs actual
		// status changes.
		$result = $this->transition->is_allowed( AiRunStatus::EDITED, AiRunStatus::EDITED, false );
		$this->assertNotTrue( $result );
	}

	// --- Rejection -----------------------------------------------------

	public function test_drafted_to_rejected_is_always_allowed(): void {
		$this->assertTrue( $this->transition->is_allowed( AiRunStatus::DRAFTED, AiRunStatus::REJECTED, false ) );
	}

	public function test_edited_to_rejected_is_always_allowed(): void {
		$this->assertTrue( $this->transition->is_allowed( AiRunStatus::EDITED, AiRunStatus::REJECTED, false ) );
	}

	// --- Terminal states -------------------------------------------------

	public function test_published_is_terminal_no_further_transitions_allowed(): void {
		foreach ( array( AiRunStatus::DRAFTED, AiRunStatus::EDITED, AiRunStatus::REJECTED ) as $target ) {
			$result = $this->transition->is_allowed( AiRunStatus::PUBLISHED, $target, true );
			$this->assertNotTrue( $result, "published -> {$target->value} must not be allowed" );
		}
	}

	public function test_rejected_is_terminal_no_further_transitions_allowed(): void {
		foreach ( array( AiRunStatus::DRAFTED, AiRunStatus::EDITED, AiRunStatus::PUBLISHED ) as $target ) {
			$result = $this->transition->is_allowed( AiRunStatus::REJECTED, $target, true );
			$this->assertNotTrue( $result, "rejected -> {$target->value} must not be allowed" );
		}
	}

	// --- Backward transitions --------------------------------------------

	public function test_cannot_move_backward_to_drafted_from_edited(): void {
		$result = $this->transition->is_allowed( AiRunStatus::EDITED, AiRunStatus::DRAFTED, true );
		$this->assertNotTrue( $result );
	}

	// --- Full matrix sanity check ----------------------------------------

	/**
	 * Walks every (from, to, human_reviewed) combination and asserts the
	 * single invariant that actually matters most: PUBLISHED is reachable
	 * if and only if human_reviewed is true (from a non-terminal state).
	 * This is a belt-and-suspenders test on top of the specific-case tests
	 * above, so a future edit to this class can't accidentally reopen the
	 * gate without a very deliberately failing test.
	 */
	public function test_published_is_never_reachable_without_human_review_across_the_full_matrix(): void {
		$all_statuses = AiRunStatus::cases();

		foreach ( $all_statuses as $from ) {
			$result = $this->transition->is_allowed( $from, AiRunStatus::PUBLISHED, false );
			$this->assertNotTrue( $result, "{$from->value} -> published with human_reviewed=false must never be allowed" );
		}
	}
}
