<?php
/**
 * @package MCPSuite\Tests\Unit\Xlsx
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\Xlsx;

use MCPSuite\Core\Xlsx\WorkbookWriter;
use MCPSuite\Core\Xlsx\XlsxWriteException;
use PHPUnit\Framework\TestCase;

final class WorkbookWriterTest extends TestCase {

	private WorkbookWriter $writer;

	protected function setUp(): void {
		parent::setUp();
		$this->writer = new WorkbookWriter();
	}

	/**
	 * Writes the given bytes to a real temp file and opens it with
	 * ZipArchive, so assertions run against the actual produced archive —
	 * not just the XML fragments that went into it.
	 */
	private function open_as_zip( string $bytes ): \ZipArchive {
		$path = tempnam( sys_get_temp_dir(), 'xlsx-test-' );
		file_put_contents( $path, $bytes );

		$zip = new \ZipArchive();
		$result = $zip->open( $path );
		$this->assertTrue( true === $result, 'produced file is not a valid zip archive' );

		unlink( $path );

		return $zip;
	}

	public function test_produced_file_starts_with_the_zip_magic_bytes(): void {
		$bytes = $this->writer->build( array( 'Summary' => array( array( 'A' ) ) ) );
		$this->assertSame( 'PK', substr( $bytes, 0, 2 ) );
	}

	public function test_produced_file_is_a_readable_zip_containing_required_ooxml_parts(): void {
		$bytes = $this->writer->build( array( 'Summary' => array( array( 'header' ), array( 'value' ) ) ) );
		$zip   = $this->open_as_zip( $bytes );

		$this->assertNotFalse( $zip->locateName( '[Content_Types].xml' ) );
		$this->assertNotFalse( $zip->locateName( '_rels/.rels' ) );
		$this->assertNotFalse( $zip->locateName( 'xl/workbook.xml' ) );
		$this->assertNotFalse( $zip->locateName( 'xl/_rels/workbook.xml.rels' ) );
		$this->assertNotFalse( $zip->locateName( 'xl/worksheets/sheet1.xml' ) );
	}

	public function test_multiple_sheets_produce_correctly_numbered_parts_and_workbook_entries(): void {
		$bytes = $this->writer->build(
			array(
				'Summary' => array( array( 'a' ) ),
				'SEO'     => array( array( 'b' ) ),
				'Backups' => array( array( 'c' ) ),
			)
		);
		$zip = $this->open_as_zip( $bytes );

		$this->assertNotFalse( $zip->locateName( 'xl/worksheets/sheet1.xml' ) );
		$this->assertNotFalse( $zip->locateName( 'xl/worksheets/sheet2.xml' ) );
		$this->assertNotFalse( $zip->locateName( 'xl/worksheets/sheet3.xml' ) );

		$workbook_xml = $zip->getFromName( 'xl/workbook.xml' );
		$parsed       = simplexml_load_string( $workbook_xml );
		$parsed->registerXPathNamespace( 'w', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );

		$names = array_map( static fn( $s ) => (string) $s['name'], $parsed->xpath( '//w:sheet' ) );
		$this->assertSame( array( 'Summary', 'SEO', 'Backups' ), $names );
	}

	public function test_worksheet_content_is_readable_and_correct_after_a_full_write_read_cycle(): void {
		$bytes = $this->writer->build(
			array(
				'Data' => array(
					array( 'Query', 'Clicks' ),
					array( 'rhinoplasty recovery', 42 ),
				),
			)
		);
		$zip = $this->open_as_zip( $bytes );

		$sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$parsed    = simplexml_load_string( $sheet_xml );

		$this->assertSame( 'Query', (string) $parsed->sheetData->row[0]->c[0]->is->t );
		$this->assertSame( 'rhinoplasty recovery', (string) $parsed->sheetData->row[1]->c[0]->is->t );
		$this->assertSame( '42', (string) $parsed->sheetData->row[1]->c[1]->v );
	}

	public function test_sheet_names_with_forbidden_characters_are_sanitized_in_the_workbook_xml(): void {
		$bytes = $this->writer->build( array( 'A:B/C' => array( array( 'x' ) ) ) );
		$zip   = $this->open_as_zip( $bytes );

		$workbook_xml = $zip->getFromName( 'xl/workbook.xml' );
		$this->assertStringNotContainsString( ':B', $workbook_xml );
		$this->assertStringContainsString( 'ABC', $workbook_xml );
	}

	public function test_empty_sheets_map_throws_rather_than_producing_an_unopenable_file(): void {
		$this->expectException( XlsxWriteException::class );
		$this->writer->build( array() );
	}

	public function test_persian_content_survives_a_full_write_read_cycle(): void {
		$text  = 'گزارش هفتگی سئو';
		$bytes = $this->writer->build( array( 'گزارش' => array( array( $text ) ) ) );
		$zip   = $this->open_as_zip( $bytes );

		$sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$parsed    = simplexml_load_string( $sheet_xml );

		$this->assertSame( $text, (string) $parsed->sheetData->row[0]->c[0]->is->t );
	}
}
