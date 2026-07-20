<?php
/**
 * Assembles a complete XLSX (OOXML SpreadsheetML) file from named sheets
 * using PHP's built-in ZipArchive — no phpoffice/phpspreadsheet dependency.
 * Unlike Content Sync's DOCX conversion (which depends on an unfetchable-
 * in-this-sandbox Composer package), this class has no external dependency
 * beyond PHP's own ext-zip, so the full file generation — not just the XML
 * fragments — is genuinely testable here, including reading the produced
 * file back with ZipArchive to confirm its structure.
 *
 * @package MCPSuite\Core\Xlsx
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Xlsx;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class WorkbookWriter {

	public function __construct(
		private readonly WorksheetXmlBuilder $worksheet_builder = new WorksheetXmlBuilder(),
		private readonly SheetNameSanitizer $name_sanitizer = new SheetNameSanitizer()
	) {}

	/**
	 * @param array<string,array<int,array<int,string|int|float|bool|null>>> $sheets sheet name => rows
	 *
	 * @throws XlsxWriteException
	 */
	public function build( array $sheets ): string {
		if ( array() === $sheets ) {
			throw new XlsxWriteException( 'A workbook must have at least one sheet.' );
		}

		$tmp_path = tempnam( sys_get_temp_dir(), 'mcp-suite-xlsx-' );
		$zip      = new \ZipArchive();

		if ( true !== $zip->open( $tmp_path, \ZipArchive::OVERWRITE ) ) {
			throw new XlsxWriteException( 'Could not open a temporary file for XLSX assembly.' );
		}

		try {
			$sheet_names = array();
			$index       = 0;
			foreach ( $sheets as $raw_name => $rows ) {
				$index++;
				$sheet_names[] = $this->name_sanitizer->sanitize( (string) $raw_name );
				$zip->addFromString( "xl/worksheets/sheet{$index}.xml", $this->worksheet_builder->build( $rows ) );
			}

			$zip->addFromString( '[Content_Types].xml', $this->content_types_xml( count( $sheets ) ) );
			$zip->addFromString( '_rels/.rels', $this->package_rels_xml() );
			$zip->addFromString( 'xl/workbook.xml', $this->workbook_xml( $sheet_names ) );
			$zip->addFromString( 'xl/_rels/workbook.xml.rels', $this->workbook_rels_xml( count( $sheets ) ) );

			$zip->close();

			$bytes = file_get_contents( $tmp_path );
			if ( false === $bytes ) {
				throw new XlsxWriteException( 'Failed to read back the assembled XLSX file.' );
			}

			return $bytes;
		} finally {
			if ( file_exists( $tmp_path ) ) {
				unlink( $tmp_path );
			}
		}
	}

	private function content_types_xml( int $sheet_count ): string {
		$overrides = '';
		for ( $i = 1; $i <= $sheet_count; $i++ ) {
			$overrides .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
			'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
			'<Default Extension="xml" ContentType="application/xml"/>' .
			'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
			$overrides .
			'</Types>';
	}

	private function package_rels_xml(): string {
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
			'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
			'</Relationships>';
	}

	/**
	 * @param string[] $sheet_names
	 */
	private function workbook_xml( array $sheet_names ): string {
		$sheets_xml = '';
		foreach ( $sheet_names as $i => $name ) {
			$sheet_id = $i + 1;
			$sheets_xml .= '<sheet name="' . htmlspecialchars( $name, ENT_XML1 | ENT_COMPAT, 'UTF-8' ) . '" sheetId="' . $sheet_id . '" r:id="rId' . $sheet_id . '"/>';
		}

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
			'<sheets>' . $sheets_xml . '</sheets>' .
			'</workbook>';
	}

	private function workbook_rels_xml( int $sheet_count ): string {
		$rels = '';
		for ( $i = 1; $i <= $sheet_count; $i++ ) {
			$rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
		}

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
			'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
	}
}
