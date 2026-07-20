<?php
/**
 * Decides what a sync tick should do for one post, given the last known
 * hashes on both sides and the current hashes on both sides. Contains no
 * I/O and touches no WordPress function — it is a pure function of four
 * strings, which is what makes the conflict-handling logic (the riskiest
 * part of this module) fully unit-testable without WordPress or Graph.
 *
 * Truth table (see spec §3.1 "Conflict handling"):
 *   WP unchanged, OneDrive unchanged -> SKIP
 *   WP changed,   OneDrive unchanged -> EXPORT (push WP -> OneDrive)
 *   WP unchanged, OneDrive changed   -> IMPORT (pull OneDrive -> WP draft revision)
 *   WP changed,   OneDrive changed   -> CONFLICT (never auto-resolved)
 *
 * A missing baseline (null $last_export_hash or $last_import_hash) is
 * treated as "changed" on that side, so decide() never mistakes an
 * unknown state for SKIP. Note this means a genuinely brand-new mirror
 * (both baselines null) resolves to CONFLICT here, not EXPORT — by
 * design. This class does not know whether "no OneDrive baseline" means
 * "no file has ever been created" or "a file exists but we never
 * recorded seeing it." Disambiguating that is the caller's job:
 * SyncOrchestrator checks `onedrive_item_id === null` and performs the
 * very first export unconditionally, without consulting decide() at all.
 * Once a mirror exists, every subsequent tick goes through this matrix.
 *
 * @package MCPSuite\Modules\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\ContentSync;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class SyncEngine {

	public function decide(
		?string $last_export_hash,
		?string $last_import_hash,
		string $current_wp_hash,
		string $current_onedrive_hash
	): SyncDecision {
		$wp_changed       = ( null === $last_export_hash ) || ! hash_equals( $last_export_hash, $current_wp_hash );
		$onedrive_changed = ( null === $last_import_hash ) || ! hash_equals( $last_import_hash, $current_onedrive_hash );

		if ( $wp_changed && $onedrive_changed ) {
			return SyncDecision::CONFLICT;
		}

		if ( $wp_changed ) {
			return SyncDecision::EXPORT;
		}

		if ( $onedrive_changed ) {
			return SyncDecision::IMPORT;
		}

		return SyncDecision::SKIP;
	}
}
