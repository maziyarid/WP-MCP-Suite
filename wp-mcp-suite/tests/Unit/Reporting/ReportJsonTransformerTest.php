<?php
/**
 * @package MCPSuite\Tests\Unit\Reporting
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\Reporting;

use MCPSuite\Modules\Reporting\ReportJsonTransformer;
use PHPUnit\Framework\TestCase;

final class ReportJsonTransformerTest extends TestCase {

	private ReportJsonTransformer $transformer;

	protected function setUp(): void {
		parent::setUp();
		$this->transformer = new ReportJsonTransformer();
	}

	public function test_transforms_header_and_rows_into_keyed_objects(): void {
		$sheets = array(
			'SEO' => array(
				array( 'Post ID', 'Issue Count' ),
				array( 42, 3 ),
				array( 7, 0 ),
			),
		);

		$result = $this->transformer->transform( $sheets );

		$this->assertSame(
			array(
				array( 'Post ID' => 42, 'Issue Count' => 3 ),
				array( 'Post ID' => 7, 'Issue Count' => 0 ),
			),
			$result['SEO']
		);
	}

	public function test_sheet_with_only_a_header_row_produces_an_empty_data_array(): void {
		$sheets = array( 'Empty' => array( array( 'Col1', 'Col2' ) ) );
		$result = $this->transformer->transform( $sheets );

		$this->assertSame( array(), $result['Empty'] );
	}

	public function test_completely_empty_sheet_produces_an_empty_array(): void {
		$sheets = array( 'Nothing' => array() );
		$result = $this->transformer->transform( $sheets );

		$this->assertSame( array(), $result['Nothing'] );
	}

	public function test_multiple_sheets_are_each_transformed_independently(): void {
		$sheets = array(
			'A' => array( array( 'x' ), array( 1 ) ),
			'B' => array( array( 'y' ), array( 2 ) ),
		);

		$result = $this->transformer->transform( $sheets );

		$this->assertSame( array( array( 'x' => 1 ) ), $result['A'] );
		$this->assertSame( array( array( 'y' => 2 ) ), $result['B'] );
	}

	public function test_row_shorter_than_header_fills_missing_columns_with_null(): void {
		$sheets = array( 'S' => array( array( 'a', 'b', 'c' ), array( 1 ) ) );
		$result = $this->transformer->transform( $sheets );

		$this->assertSame( array( 'a' => 1, 'b' => null, 'c' => null ), $result['S'][0] );
	}

	public function test_result_is_json_encodable_end_to_end(): void {
		$sheets = array( 'S' => array( array( 'name' ), array( 'راهنما' ) ) );
		$result = $this->transformer->transform( $sheets );

		$json = json_encode( $result, JSON_UNESCAPED_UNICODE );
		$this->assertNotFalse( $json );
		$this->assertStringContainsString( 'راهنما', $json );
	}
}
