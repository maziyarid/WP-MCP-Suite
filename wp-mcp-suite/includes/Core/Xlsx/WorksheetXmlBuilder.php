<?php
/**
 * Builds one worksheet's XML (SpreadsheetML) from a plain array of rows.
 * Uses inline strings (t="inlineStr") rather than a shared-strings table,
 * which is valid OOXML and avoids the bookkeeping a shared-strings index
 * would add for no real benefit at this plugin's data volumes.
 *
 * Pure string building — no ZipArchive, no filesystem — so the actual XML
 * shape (escaping, cell references, numeric vs. string typing) is tested
 * directly against the string output, not just assumed correct because a
 * file got produced.
 *
 * @package MCPSuite\Core\Xlsx
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Xlsx;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class WorksheetXmlBuilder {

	/**
	 * @param array<int,array<int,string|int|float|bool|null>> $rows Each row is a plain list of cell values, in column order.
	 */
	public function build( array $rows ): string {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
			'<sheetData>';

		foreach ( array_values( $rows ) as $row_index => $row ) {
			$row_number = $row_index + 1;
			$xml       .= '<row r="' . $row_number . '">';

			foreach ( array_values( $row ) as $col_index => $value ) {
				$xml .= $this->cell_xml( $col_index, $row_number, $value );
			}

			$xml .= '</row>';
		}

		$xml .= '</sheetData></worksheet>';

		return $xml;
	}

	private function cell_xml( int $col_index, int $row_number, string|int|float|bool|null $value ): string {
		$ref = ColumnRef::cell( $col_index, $row_number );

		if ( null === $value || '' === $value ) {
			return '<c r="' . $ref . '"/>';
		}

		if ( is_bool( $value ) ) {
			return '<c r="' . $ref . '" t="b"><v>' . ( $value ? 1 : 0 ) . '</v></c>';
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			// A raw numeric value must never be NaN/Infinite in valid XML content.
			$numeric = is_finite( (float) $value ) ? (string) $value : '0';
			return '<c r="' . $ref . '"><v>' . $numeric . '</v></c>';
		}

		return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $this->escape( (string) $value ) . '</t></is></c>';
	}

	private function escape( string $value ): string {
		// ENT_XML1 produces valid XML entity escaping (&amp; &lt; &gt; &apos; &quot;);
		// control characters outside the small allowed set (tab/LF/CR) are
		// invalid in XML 1.0 and are stripped rather than left to corrupt the file.
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value ) ?? $value;

		return htmlspecialchars( $value, ENT_XML1 | ENT_COMPAT, 'UTF-8' );
	}
}
