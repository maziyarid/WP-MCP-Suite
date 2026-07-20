<?php
/**
 * @package MCPSuite\Modules\Backup
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Backup;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

enum BackupJobStatus: string {
	case PENDING  = 'pending';
	case UPLOADED = 'uploaded';
	case VERIFIED = 'verified';
	case FAILED   = 'failed';
	case PURGED   = 'purged';
}
