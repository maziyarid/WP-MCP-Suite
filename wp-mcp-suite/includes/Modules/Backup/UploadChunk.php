<?php
/**
 * @package MCPSuite\Modules\Backup
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Backup;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class UploadChunk {

	public function __construct(
		public readonly int $start,
		public readonly int $end,
		public readonly int $length,
		public readonly int $total_size
	) {}

	public function content_range_header(): string {
		return sprintf( 'bytes %d-%d/%d', $this->start, $this->end, $this->total_size );
	}

	public function is_last(): bool {
		return $this->end === $this->total_size - 1;
	}
}
