<?php
/**
 * @package MCPSuite\Core\Xlsx
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Xlsx;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class XlsxWriteException extends \RuntimeException {}
