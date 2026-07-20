<?php
/**
 * @package MCPSuite\Tests\Unit\SearchConsole
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\SearchConsole;

use MCPSuite\Modules\SearchConsole\GscResponseParser;
use PHPUnit\Framework\TestCase;

final class GscResponseParserTest extends TestCase {

	private GscResponseParser $parser;

	protected function setUp(): void {
		parent::setUp();
		$this->parser = new GscResponseParser();
	}

	public function test_parses_a_realistic_response(): void {
		$response = array(
			'rows' => array(
				array(
					'keys'        => array( 'https://drbastaninejad.com/rhinoplasty/', 'rhinoplasty los angeles', 'usa', 'MOBILE' ),
					'clicks'      => 42,
					'impressions' => 1050,
					'ctr'         => 0.04,
					'position'    => 8.3,
				),
			),
		);

		$rows = $this->parser->parse( $response, '2026-07-05' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'https://drbastaninejad.com/rhinoplasty/', $rows[0]->page_url );
		$this->assertSame( 'rhinoplasty los angeles', $rows[0]->query );
		$this->assertSame( 'usa', $rows[0]->country );
		$this->assertSame( 'mobile', $rows[0]->device, 'device should be normalized to lowercase' );
		$this->assertSame( '2026-07-05', $rows[0]->data_date );
		$this->assertSame( 42, $rows[0]->clicks );
		$this->assertSame( 1050, $rows[0]->impressions );
		$this->assertEqualsWithDelta( 0.04, $rows[0]->ctr, 0.0001 );
		$this->assertEqualsWithDelta( 8.3, $rows[0]->position, 0.0001 );
	}

	public function test_empty_rows_produces_empty_array(): void {
		$rows = $this->parser->parse( array( 'rows' => array() ), '2026-07-05' );
		$this->assertSame( array(), $rows );
	}

	public function test_missing_rows_key_produces_empty_array_not_an_error(): void {
		// GSC returns a response with no "rows" key at all when there is no data for the period.
		$rows = $this->parser->parse( array(), '2026-07-05' );
		$this->assertSame( array(), $rows );
	}

	public function test_malformed_row_with_too_few_keys_is_skipped_not_fatal(): void {
		$response = array(
			'rows' => array(
				array( 'keys' => array( 'only', 'two' ), 'clicks' => 1 ),
				array( 'keys' => array( 'a', 'b', 'c', 'd' ), 'clicks' => 5, 'impressions' => 10, 'ctr' => 0.5, 'position' => 1.0 ),
			),
		);

		$rows = $this->parser->parse( $response, '2026-07-05' );

		$this->assertCount( 1, $rows, 'the malformed row should be skipped, not crash parsing of the whole batch' );
		$this->assertSame( 5, $rows[0]->clicks );
	}

	public function test_missing_metric_fields_default_to_zero_rather_than_erroring(): void {
		$response = array( 'rows' => array( array( 'keys' => array( 'p', 'q', 'c', 'd' ) ) ) );

		$rows = $this->parser->parse( $response, '2026-07-05' );

		$this->assertSame( 0, $rows[0]->clicks );
		$this->assertSame( 0, $rows[0]->impressions );
		$this->assertSame( 0.0, $rows[0]->ctr );
		$this->assertSame( 0.0, $rows[0]->position );
	}

	public function test_multiple_rows_parsed_in_order(): void {
		$response = array(
			'rows' => array(
				array( 'keys' => array( 'p1', 'q1', 'usa', 'DESKTOP' ), 'clicks' => 1, 'impressions' => 1, 'ctr' => 1, 'position' => 1 ),
				array( 'keys' => array( 'p2', 'q2', 'gbr', 'TABLET' ), 'clicks' => 2, 'impressions' => 2, 'ctr' => 2, 'position' => 2 ),
			),
		);

		$rows = $this->parser->parse( $response, '2026-07-05' );

		$this->assertCount( 2, $rows );
		$this->assertSame( 'p1', $rows[0]->page_url );
		$this->assertSame( 'p2', $rows[1]->page_url );
	}
}
