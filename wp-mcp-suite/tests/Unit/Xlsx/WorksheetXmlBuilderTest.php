<?php
/**
 * @package MCPSuite\Tests\Unit\Xlsx
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\Xlsx;

use MCPSuite\Core\Xlsx\WorksheetXmlBuilder;
use PHPUnit\Framework\TestCase;

final class WorksheetXmlBuilderTest extends TestCase {

	private WorksheetXmlBuilder $builder;

	protected function setUp(): void {
		parent::setUp();
		$this->builder = new WorksheetXmlBuilder();
	}

	private function parse( string $xml ): \SimpleXMLElement {
		$parsed = simplexml_load_string( $xml );
		$this->assertNotFalse( $parsed, 'builder output was not well-formed XML' );
		return $parsed;
	}

	public function test_produces_well_formed_xml_for_a_typical_table(): void {
		$xml = $this->builder->build(
			array(
				array( 'Query', 'Clicks', 'CTR' ),
				array( 'rhinoplasty los angeles', 42, 0.04 ),
			)
		);

		$sheet = $this->parse( $xml );
		$this->assertCount( 2, $sheet->sheetData->row );
	}

	public function test_string_cell_uses_inline_string_type_with_correct_text(): void {
		$xml   = $this->builder->build( array( array( 'Hello World' ) ) );
		$sheet = $xml;

		$this->assertStringContainsString( 't="inlineStr"', $xml );

		$parsed = $this->parse( $xml );
		$cell   = $parsed->sheetData->row[0]->c[0];
		$this->assertSame( 'Hello World', (string) $cell->is->t );
	}

	public function test_integer_cell_has_no_type_attribute_and_correct_numeric_value(): void {
		$xml    = $this->builder->build( array( array( 42 ) ) );
		$parsed = $this->parse( $xml );
		$cell   = $parsed->sheetData->row[0]->c[0];

		$this->assertSame( '', (string) $cell['t'] );
		$this->assertSame( '42', (string) $cell->v );
	}

	public function test_float_cell_preserves_value(): void {
		$xml    = $this->builder->build( array( array( 3.14 ) ) );
		$parsed = $this->parse( $xml );
		$this->assertEqualsWithDelta( 3.14, (float) $parsed->sheetData->row[0]->c[0]->v, 0.001 );
	}

	public function test_boolean_cell_uses_boolean_type(): void {
		$xml    = $this->builder->build( array( array( true, false ) ) );
		$parsed = $this->parse( $xml );

		$this->assertSame( 'b', (string) $parsed->sheetData->row[0]->c[0]['t'] );
		$this->assertSame( '1', (string) $parsed->sheetData->row[0]->c[0]->v );
		$this->assertSame( '0', (string) $parsed->sheetData->row[0]->c[1]->v );
	}

	public function test_null_and_empty_string_cells_produce_no_value_element(): void {
		$xml    = $this->builder->build( array( array( null, '' ) ) );
		$parsed = $this->parse( $xml );

		$this->assertSame( 0, count( $parsed->sheetData->row[0]->c[0]->children() ) );
	}

	public function test_cell_references_are_correct_across_multiple_columns_and_rows(): void {
		$xml    = $this->builder->build( array( array( 'a', 'b', 'c' ), array( 'd', 'e', 'f' ) ) );
		$parsed = $this->parse( $xml );

		$this->assertSame( 'A1', (string) $parsed->sheetData->row[0]->c[0]['r'] );
		$this->assertSame( 'C1', (string) $parsed->sheetData->row[0]->c[2]['r'] );
		$this->assertSame( 'A2', (string) $parsed->sheetData->row[1]->c[0]['r'] );
		$this->assertSame( 'C2', (string) $parsed->sheetData->row[1]->c[2]['r'] );
	}

	public function test_special_xml_characters_are_escaped_and_survive_round_trip(): void {
		$xml    = $this->builder->build( array( array( 'Tom & Jerry <says> "hi" it\'s fine' ) ) );
		$parsed = $this->parse( $xml ); // simplexml_load_string would fail on unescaped & < >

		$this->assertSame( 'Tom & Jerry <says> "hi" it\'s fine', (string) $parsed->sheetData->row[0]->c[0]->is->t );
	}

	public function test_persian_farsi_content_survives_the_xml_round_trip(): void {
		$text   = 'راهنمای ریکاوری بعد از رینوپلاستی';
		$xml    = $this->builder->build( array( array( $text ) ) );
		$parsed = $this->parse( $xml );

		$this->assertSame( $text, (string) $parsed->sheetData->row[0]->c[0]->is->t );
	}

	public function test_control_characters_invalid_in_xml_are_stripped_not_left_to_corrupt_the_document(): void {
		$dirty = "Bad\x01Value\x02Here";
		$xml   = $this->builder->build( array( array( $dirty ) ) );

		$this->parse( $xml ); // must still be well-formed XML despite the control chars
	}

	public function test_infinite_or_nan_float_does_not_produce_invalid_xml(): void {
		$xml = $this->builder->build( array( array( INF, NAN ) ) );
		$this->parse( $xml ); // must not throw / must still parse
	}

	public function test_empty_rows_array_produces_a_valid_empty_worksheet(): void {
		$xml    = $this->builder->build( array() );
		$parsed = $this->parse( $xml );
		$this->assertSame( 0, count( $parsed->sheetData->row ) );
	}
}
