<?php
/**
 * Validates AI-run status transitions. This is the literal enforcement of
 * spec §2's negative requirement: "No AI-generated content publishes
 * without a human explicitly changing human_reviewed to 1 and status to
 * 'published'... enforced in code, not left as a policy note." Every
 * caller that would change an ai_runs row's status — the REST controller,
 * the WordPress publish-hook interceptor, anything written later — must
 * go through this class's is_allowed() first. There is no other path to
 * PUBLISHED in this codebase.
 *
 * Pure: no WordPress, no database, so the entire transition table is
 * exhaustively unit tested rather than only reachable through integration
 * tests, given how much this specific rule matters.
 *
 * @package MCPSuite\Modules\AiWriting
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\AiWriting;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class AiRunStatusTransition {

	/**
	 * @return true|string true if allowed, or a human-readable reason it is not.
	 */
	public function is_allowed( AiRunStatus $from, AiRunStatus $to, bool $human_reviewed ): true|string {
		if ( $from === $to ) {
			return 'no-op transition to the same status is not meaningful';
		}

		if ( AiRunStatus::PUBLISHED === $from || AiRunStatus::REJECTED === $from ) {
			return 'published and rejected are terminal states in this workflow; further changes happen through normal WordPress post management, not this queue';
		}

		return match ( $to ) {
			AiRunStatus::PUBLISHED => $human_reviewed
				? true
				: 'cannot publish: human_reviewed must be true before a status may become published — this is the mandatory review gate',
			AiRunStatus::EDITED    => AiRunStatus::DRAFTED === $from
				? true
				: 'can only move to edited from drafted',
			AiRunStatus::REJECTED  => true, // rejecting from drafted or edited is always allowed
			AiRunStatus::DRAFTED   => 'cannot move backward to drafted',
		};
	}

	public function assert_allowed( AiRunStatus $from, AiRunStatus $to, bool $human_reviewed ): void {
		$result = $this->is_allowed( $from, $to, $human_reviewed );

		if ( true !== $result ) {
			throw new InvalidStatusTransitionException( (string) $result );
		}
	}
}
