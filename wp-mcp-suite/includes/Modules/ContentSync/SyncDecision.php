<?php
/**
 * @package MCPSuite\Modules\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\ContentSync;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

enum SyncDecision: string {
	case SKIP     = 'skip';
	case EXPORT   = 'export';
	case IMPORT   = 'import';
	case CONFLICT = 'conflict';
}
