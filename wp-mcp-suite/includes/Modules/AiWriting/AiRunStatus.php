<?php
/**
 * @package MCPSuite\Modules\AiWriting
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\AiWriting;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

enum AiRunStatus: string {
	case DRAFTED   = 'drafted';
	case EDITED    = 'edited';
	case PUBLISHED = 'published';
	case REJECTED  = 'rejected';
}
