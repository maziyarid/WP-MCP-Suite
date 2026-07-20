<?php
/**
 * Enforces Excel's documented worksheet-name restrictions: max 31
 * characters, and none of : \ / ? * [ ] may appear. A workbook with an
 * invalid sheet name fails to open in Excel at all, so this is worth
 * getting right rather than assuming module labels are always safe.
 *
 * @package MCPSuite\Core\Xlsx
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Xlsx;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class SheetNameSanitizer {

	private const MAX_LENGTH = 31;
	private const FORBIDDEN  = array( ':', '\\', '/', '?', '*', '[', ']' );

	public function sanitize( string $name ): string {
		$clean = str_replace( self::FORBIDDEN, '', $name );
		$clean = trim( $clean );

		if ( '' === $clean ) {
			$clean = 'Sheet';
		}

		return mb_substr( $clean, 0, self::MAX_LENGTH );
	}
}
