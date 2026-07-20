<?php
/**
 * Decides which backup jobs are eligible for pruning. This is the literal
 * enforcement of spec §2's negative requirement: "pruning... only deletes
 * backups past retention AND already confirmed uploaded+verified — never
 * deletes a backup whose upload status is unknown." Only BackupJobStatus::VERIFIED
 * jobs are ever eligible; PENDING, UPLOADED-but-not-yet-verified, and
 * FAILED jobs are never touched by this policy, regardless of age — an
 * unverified backup staying around too long is a visibility problem to
 * surface (see BackupModule's audit logging), not something this class
 * silently cleans up.
 *
 * Pure: no WordPress, no database, no filesystem — a deletion-adjacent
 * policy is exactly the kind of logic that deserves to be exhaustively
 * unit tested rather than only reachable through a live cron run.
 *
 * @package MCPSuite\Modules\Backup
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Backup;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class BackupRetentionPolicy {

	/**
	 * Even if misconfigured with $keep_count = 0, at least one verified
	 * backup is always kept — a retention policy must never be able to
	 * delete every recovery point a site has.
	 */
	private const MINIMUM_KEPT_REGARDLESS_OF_CONFIG = 1;

	/**
	 * @param BackupJob[] $jobs Any order; not assumed pre-sorted.
	 * @return BackupJob[] Jobs eligible for pruning, oldest-verified-first.
	 */
	public function jobs_to_prune( array $jobs, int $keep_count ): array {
		$keep_count = max( self::MINIMUM_KEPT_REGARDLESS_OF_CONFIG, $keep_count );

		$verified = array_values( array_filter( $jobs, static fn( BackupJob $j ) => BackupJobStatus::VERIFIED === $j->status ) );

		usort( $verified, static fn( BackupJob $a, BackupJob $b ) => strcmp( $b->started_at, $a->started_at ) ); // newest first

		return array_slice( $verified, $keep_count );
	}
}
