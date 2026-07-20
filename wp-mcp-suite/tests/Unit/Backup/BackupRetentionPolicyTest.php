<?php
/**
 * @package MCPSuite\Tests\Unit\Backup
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\Backup;

use MCPSuite\Modules\Backup\BackupJob;
use MCPSuite\Modules\Backup\BackupJobStatus;
use MCPSuite\Modules\Backup\BackupRetentionPolicy;
use PHPUnit\Framework\TestCase;

final class BackupRetentionPolicyTest extends TestCase {

	private BackupRetentionPolicy $policy;

	protected function setUp(): void {
		parent::setUp();
		$this->policy = new BackupRetentionPolicy();
	}

	private function job( int $id, BackupJobStatus $status, string $started_at ): BackupJob {
		return new BackupJob( $id, "job-{$id}", "backup-{$id}.sql.gz.enc", $status, $started_at );
	}

	public function test_pending_jobs_are_never_eligible_for_pruning_regardless_of_age(): void {
		$jobs = array( $this->job( 1, BackupJobStatus::PENDING, '2020-01-01 00:00:00' ) );
		$this->assertSame( array(), $this->policy->jobs_to_prune( $jobs, 8 ) );
	}

	public function test_uploaded_but_not_verified_jobs_are_never_eligible_for_pruning(): void {
		// This is the exact case the negative requirement names: "never
		// deletes a backup whose upload status is unknown" — uploaded but
		// not yet confirmed-verified counts as unknown/unconfirmed.
		$jobs = array( $this->job( 1, BackupJobStatus::UPLOADED, '2020-01-01 00:00:00' ) );
		$this->assertSame( array(), $this->policy->jobs_to_prune( $jobs, 0 ) );
	}

	public function test_failed_jobs_are_never_eligible_for_pruning(): void {
		$jobs = array( $this->job( 1, BackupJobStatus::FAILED, '2020-01-01 00:00:00' ) );
		$this->assertSame( array(), $this->policy->jobs_to_prune( $jobs, 8 ) );
	}

	public function test_keeps_the_n_most_recent_verified_jobs_and_prunes_the_rest(): void {
		$jobs = array(
			$this->job( 1, BackupJobStatus::VERIFIED, '2026-01-01 00:00:00' ),
			$this->job( 2, BackupJobStatus::VERIFIED, '2026-01-08 00:00:00' ),
			$this->job( 3, BackupJobStatus::VERIFIED, '2026-01-15 00:00:00' ),
		);

		$to_prune = $this->policy->jobs_to_prune( $jobs, 2 );

		$this->assertCount( 1, $to_prune );
		$this->assertSame( 1, $to_prune[0]->id, 'the oldest verified job should be the one pruned' );
	}

	public function test_fewer_verified_jobs_than_keep_count_prunes_nothing(): void {
		$jobs = array(
			$this->job( 1, BackupJobStatus::VERIFIED, '2026-01-01 00:00:00' ),
			$this->job( 2, BackupJobStatus::VERIFIED, '2026-01-08 00:00:00' ),
		);

		$this->assertSame( array(), $this->policy->jobs_to_prune( $jobs, 8 ) );
	}

	public function test_a_mix_of_statuses_only_ever_prunes_from_the_verified_subset(): void {
		$jobs = array(
			$this->job( 1, BackupJobStatus::VERIFIED, '2026-01-01 00:00:00' ),
			$this->job( 2, BackupJobStatus::VERIFIED, '2026-01-08 00:00:00' ),
			$this->job( 3, BackupJobStatus::FAILED, '2026-01-09 00:00:00' ),
			$this->job( 4, BackupJobStatus::PENDING, '2026-01-10 00:00:00' ),
		);

		$to_prune = $this->policy->jobs_to_prune( $jobs, 1 );

		$this->assertCount( 1, $to_prune );
		$this->assertSame( 1, $to_prune[0]->id );
	}

	public function test_misconfigured_zero_keep_count_still_keeps_at_least_one_backup(): void {
		$jobs = array(
			$this->job( 1, BackupJobStatus::VERIFIED, '2026-01-01 00:00:00' ),
			$this->job( 2, BackupJobStatus::VERIFIED, '2026-01-08 00:00:00' ),
		);

		$to_prune = $this->policy->jobs_to_prune( $jobs, 0 );

		$this->assertCount( 1, $to_prune, 'exactly one (the older) should be pruned, the newest must survive even with keep_count=0' );
		$this->assertSame( 1, $to_prune[0]->id );
	}

	public function test_negative_keep_count_is_treated_the_same_as_zero(): void {
		$jobs = array(
			$this->job( 1, BackupJobStatus::VERIFIED, '2026-01-01 00:00:00' ),
			$this->job( 2, BackupJobStatus::VERIFIED, '2026-01-08 00:00:00' ),
		);

		$to_prune = $this->policy->jobs_to_prune( $jobs, -5 );

		$this->assertCount( 1, $to_prune );
	}

	public function test_empty_job_list_prunes_nothing(): void {
		$this->assertSame( array(), $this->policy->jobs_to_prune( array(), 8 ) );
	}

	public function test_unsorted_input_is_still_handled_correctly(): void {
		// Deliberately out-of-order input — the policy must sort itself, not
		// assume the caller already sorted.
		$jobs = array(
			$this->job( 3, BackupJobStatus::VERIFIED, '2026-01-15 00:00:00' ),
			$this->job( 1, BackupJobStatus::VERIFIED, '2026-01-01 00:00:00' ),
			$this->job( 2, BackupJobStatus::VERIFIED, '2026-01-08 00:00:00' ),
		);

		$to_prune = $this->policy->jobs_to_prune( $jobs, 2 );

		$this->assertCount( 1, $to_prune );
		$this->assertSame( 1, $to_prune[0]->id );
	}
}
