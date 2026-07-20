<?php
/**
 * @package MCPSuite\Modules\Backup
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Backup;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class BackupJob {

	public function __construct(
		public readonly int $id,
		public readonly string $job_id,
		public readonly string $file_name,
		public readonly BackupJobStatus $status,
		public readonly string $started_at,
		public readonly ?string $storage_path = null
	) {}
}
