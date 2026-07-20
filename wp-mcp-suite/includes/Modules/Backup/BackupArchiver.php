<?php
/**
 * Thin wrapper over PHP's built-in zlib gzencode/gzdecode. Kept as its own
 * class (rather than calling gzencode() inline in the backup orchestrator)
 * so compression is swappable and directly unit-testable with a real
 * round-trip, not just assumed to work.
 *
 * @package MCPSuite\Modules\Backup
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Backup;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class BackupArchiver {

	private const COMPRESSION_LEVEL = 6; // zlib default; good balance of speed vs. ratio for a cron-run backup job

	/**
	 * @throws BackupException
	 */
	public function compress( string $raw_sql ): string {
		$compressed = gzencode( $raw_sql, self::COMPRESSION_LEVEL );

		if ( false === $compressed ) {
			throw new BackupException( 'gzencode() failed to compress the database dump.' );
		}

		return $compressed;
	}

	/**
	 * @throws BackupException
	 */
	public function decompress( string $compressed ): string {
		// gzdecode() emits a PHP warning (not just a false return) on
		// corrupt/non-gzip input. The warning is deliberately suppressed
		// here because this method already turns a false return into a
		// proper, catchable BackupException immediately below — silencing
		// the warning does not hide the failure, it just avoids letting a
		// raw engine warning leak past this class's own error handling.
		$raw = @gzdecode( $compressed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( false === $raw ) {
			throw new BackupException( 'gzdecode() failed — the archive is corrupt or not gzip-compressed.' );
		}

		return $raw;
	}
}
