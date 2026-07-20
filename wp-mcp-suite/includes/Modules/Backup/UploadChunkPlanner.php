<?php
/**
 * Computes the byte ranges for a Microsoft Graph resumable upload session
 * (createUploadSession + chunked PUT with Content-Range headers). Per
 * Graph's documented requirements, every chunk except the last must be a
 * multiple of 320 KiB (327,680 bytes).
 *
 * Deliberately extracted as pure, dependency-free logic: an off-by-one
 * error in a Content-Range header is exactly the kind of bug that would
 * corrupt every backup silently (Graph may accept a slightly-wrong range
 * and still assemble a truncated or overlapping file) — this is worth
 * testing directly rather than only through a live upload.
 *
 * @package MCPSuite\Modules\Backup
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Backup;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class UploadChunkPlanner {

	public const GRAPH_CHUNK_ALIGNMENT = 327680; // 320 KiB, per Graph's documented requirement

	/**
	 * @throws \InvalidArgumentException If $chunk_size is not a positive multiple of the required alignment.
	 * @return UploadChunk[]
	 */
	public function plan( int $total_size, int $chunk_size ): array {
		if ( $total_size < 0 ) {
			throw new \InvalidArgumentException( 'total_size must not be negative.' );
		}

		if ( $chunk_size <= 0 || 0 !== $chunk_size % self::GRAPH_CHUNK_ALIGNMENT ) {
			throw new \InvalidArgumentException( 'chunk_size must be a positive multiple of ' . self::GRAPH_CHUNK_ALIGNMENT . ' bytes.' );
		}

		if ( 0 === $total_size ) {
			return array();
		}

		$chunks = array();
		$offset = 0;

		while ( $offset < $total_size ) {
			$length     = min( $chunk_size, $total_size - $offset );
			$range_end  = $offset + $length - 1; // Content-Range end is inclusive
			$chunks[]   = new UploadChunk( $offset, $range_end, $length, $total_size );
			$offset    += $length;
		}

		return $chunks;
	}
}
