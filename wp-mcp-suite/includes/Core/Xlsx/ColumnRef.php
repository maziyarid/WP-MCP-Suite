<?php
/**
 * Converts a zero-based column index to its spreadsheet letter reference
 * (0 -> A, 25 -> Z, 26 -> AA, 701 -> ZZ, 702 -> AAA, ...). Pure integer
 * math, no dependency, so it's tested directly rather than only observed
 * indirectly through generated XML.
 *
 * @package MCPSuite\Core\Xlsx
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Xlsx;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class ColumnRef {

	public static function letter( int $zero_based_index ): string {
		if ( $zero_based_index < 0 ) {
			throw new \InvalidArgumentException( 'Column index must not be negative.' );
		}

		$letter = '';
		$index  = $zero_based_index;

		do {
			$remainder = $index % 26;
			$letter    = chr( 65 + $remainder ) . $letter;
			$index     = intdiv( $index, 26 ) - 1;
		} while ( $index >= 0 );

		return $letter;
	}

	public static function cell( int $zero_based_col, int $one_based_row ): string {
		return self::letter( $zero_based_col ) . $one_based_row;
	}
}
