<?php
/**
 * Repository for wp_mcp_ai_runs. approve() and reject() are the ONLY
 * methods in the codebase that change a run's status — both validate
 * through AiRunStatusTransition before touching the database, so the
 * mandatory human-review gate is enforced at the single choke point every
 * caller (REST controller, future admin-UI code) must go through.
 *
 * @package MCPSuite\Modules\AiWriting
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\AiWriting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AiRunRepository {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mcp_ai_runs';
	}

	public function create(
		string $job_id,
		?int $post_id,
		string $provider,
		string $model,
		string $prompt,
		string $output,
		bool $contains_phi_flag
	): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array(
				'job_id'         => $job_id,
				'post_id'        => $post_id,
				'provider'       => $provider,
				'model'          => $model,
				'prompt_hash'    => hash( 'sha256', $prompt ),
				'prompt_excerpt' => mb_substr( $prompt, 0, 500 ),
				'output_hash'    => hash( 'sha256', $output ),
				'status'         => AiRunStatus::DRAFTED->value,
				'human_reviewed' => 0,
				'contains_phi_flag' => $contains_phi_flag ? 1 : 0,
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public function find( int $run_id ): ?AiRun {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d", $run_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * @return AiRun[]
	 */
	public function find_pending_review( int $limit = 50 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE human_reviewed = 0 AND status IN ('drafted','edited') ORDER BY created_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), $rows ?: array() );
	}

	/**
	 * The mandatory review gate, enforced here. Throws
	 * InvalidStatusTransitionException (uncaught, intentionally — a caller
	 * attempting an invalid transition is a bug in that caller, not a
	 * recoverable runtime condition) if the run has not been marked
	 * human-reviewed.
	 */
	public function approve_and_publish( int $run_id, int $reviewer_id ): void {
		$run = $this->find( $run_id );
		if ( null === $run ) {
			throw new \RuntimeException( "AI run {$run_id} does not exist." );
		}

		( new AiRunStatusTransition() )->assert_allowed( $run->status, AiRunStatus::PUBLISHED, true );

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array( 'status' => AiRunStatus::PUBLISHED->value, 'human_reviewed' => 1, 'reviewer_id' => $reviewer_id ),
			array( 'id' => $run_id ),
			array( '%s', '%d', '%d' ),
			array( '%d' )
		);
	}

	public function reject( int $run_id, int $reviewer_id ): void {
		$run = $this->find( $run_id );
		if ( null === $run ) {
			throw new \RuntimeException( "AI run {$run_id} does not exist." );
		}

		( new AiRunStatusTransition() )->assert_allowed( $run->status, AiRunStatus::REJECTED, $run->human_reviewed );

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->table(),
			array( 'status' => AiRunStatus::REJECTED->value, 'reviewer_id' => $reviewer_id ),
			array( 'id' => $run_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	public function find_by_post_id( int $post_id ): ?AiRun {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$this->table()} WHERE post_id = %d ORDER BY id DESC LIMIT 1", $post_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * @return AiRun[]
	 */
	public function find_recent( int $limit = 100 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$this->table()} ORDER BY created_at DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), $rows ?: array() );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function hydrate( array $row ): AiRun {
		return new AiRun(
			id: (int) $row['id'],
			job_id: (string) $row['job_id'],
			post_id: isset( $row['post_id'] ) ? (int) $row['post_id'] : null,
			provider: (string) $row['provider'],
			model: (string) $row['model'],
			status: AiRunStatus::from( (string) $row['status'] ),
			human_reviewed: (bool) $row['human_reviewed'],
			reviewer_id: isset( $row['reviewer_id'] ) ? (int) $row['reviewer_id'] : null,
			contains_phi_flag: (bool) $row['contains_phi_flag']
		);
	}
}
