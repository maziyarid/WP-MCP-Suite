<?php
/**
 * @package MCPSuite\Tests\Unit\Analytics
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\Analytics;

use MCPSuite\Modules\Analytics\GaResponseParser;
use PHPUnit\Framework\TestCase;

final class GaResponseParserTest extends TestCase {

	private GaResponseParser $parser;

	protected function setUp(): void {
		parent::setUp();
		$this->parser = new GaResponseParser();
	}

	private function realistic_response(): array {
		return array(
			'dimensionHeaders' => array( array( 'name' => 'landingPage' ), array( 'name' => 'sessionSourceMedium' ) ),
			'metricHeaders'    => array(
				array( 'name' => 'sessions', 'type' => 'TYPE_INTEGER' ),
				array( 'name' => 'totalUsers', 'type' => 'TYPE_INTEGER' ),
				array( 'name' => 'engagedSessions', 'type' => 'TYPE_INTEGER' ),
				array( 'name' => 'engagementRate', 'type' => 'TYPE_FLOAT' ),
				array( 'name' => 'conversions', 'type' => 'TYPE_INTEGER' ),
				array( 'name' => 'eventCount', 'type' => 'TYPE_INTEGER' ),
			),
			'rows'             => array(
				array(
					'dimensionValues' => array( array( 'value' => '/rhinoplasty/' ), array( 'value' => 'google / organic' ) ),
					'metricValues'    => array(
						array( 'value' => '120' ),
						array( 'value' => '95' ),
						array( 'value' => '80' ),
						array( 'value' => '0.6667' ),
						array( 'value' => '5' ),
						array( 'value' => '300' ),
					),
				),
			),
		);
	}

	public function test_parses_a_realistic_response(): void {
		$rows = $this->parser->parse( $this->realistic_response(), '2026-07-08' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '/rhinoplasty/', $rows[0]->landing_page );
		$this->assertSame( '2026-07-08', $rows[0]->data_date );
		$this->assertSame( 120, $rows[0]->sessions );
		$this->assertSame( 95, $rows[0]->users );
		$this->assertSame( 80, $rows[0]->engaged_sessions );
		$this->assertEqualsWithDelta( 0.6667, $rows[0]->engagement_rate, 0.0001 );
		$this->assertSame( 5, $rows[0]->conversions );
		$this->assertSame( 300, $rows[0]->event_count );
		$this->assertSame( 'google / organic', $rows[0]->source_medium );
	}

	public function test_maps_by_header_name_even_if_column_order_differs_from_our_request(): void {
		// Deliberately reorder both headers and values vs. the "realistic"
		// fixture above, to prove the parser does not assume positional order.
		$response = array(
			'dimensionHeaders' => array( array( 'name' => 'sessionSourceMedium' ), array( 'name' => 'landingPage' ) ),
			'metricHeaders'    => array( array( 'name' => 'totalUsers' ), array( 'name' => 'sessions' ) ),
			'rows'             => array(
				array(
					'dimensionValues' => array( array( 'value' => 'direct / none' ), array( 'value' => '/about/' ) ),
					'metricValues'    => array( array( 'value' => '10' ), array( 'value' => '20' ) ),
				),
			),
		);

		$rows = $this->parser->parse( $response, '2026-07-08' );

		$this->assertSame( '/about/', $rows[0]->landing_page );
		$this->assertSame( 'direct / none', $rows[0]->source_medium );
		$this->assertSame( 20, $rows[0]->sessions );
		$this->assertSame( 10, $rows[0]->users );
	}

	public function test_row_missing_landing_page_dimension_is_skipped(): void {
		$response = array(
			'dimensionHeaders' => array( array( 'name' => 'sessionSourceMedium' ) ), // landingPage header absent entirely
			'metricHeaders'    => array( array( 'name' => 'sessions' ) ),
			'rows'             => array(
				array( 'dimensionValues' => array( array( 'value' => 'google / organic' ) ), 'metricValues' => array( array( 'value' => '5' ) ) ),
			),
		);

		$rows = $this->parser->parse( $response, '2026-07-08' );
		$this->assertSame( array(), $rows );
	}

	public function test_missing_metric_headers_default_metrics_to_zero(): void {
		$response = array(
			'dimensionHeaders' => array( array( 'name' => 'landingPage' ) ),
			'metricHeaders'    => array(),
			'rows'             => array(
				array( 'dimensionValues' => array( array( 'value' => '/p/' ) ), 'metricValues' => array() ),
			),
		);

		$rows = $this->parser->parse( $response, '2026-07-08' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 0, $rows[0]->sessions );
		$this->assertSame( 0.0, $rows[0]->engagement_rate );
	}

	public function test_missing_source_medium_dimension_defaults_to_empty_string_not_null(): void {
		$response = array(
			'dimensionHeaders' => array( array( 'name' => 'landingPage' ) ),
			'metricHeaders'    => array( array( 'name' => 'sessions' ) ),
			'rows'             => array(
				array( 'dimensionValues' => array( array( 'value' => '/p/' ) ), 'metricValues' => array( array( 'value' => '1' ) ) ),
			),
		);

		$rows = $this->parser->parse( $response, '2026-07-08' );
		$this->assertSame( '', $rows[0]->source_medium );
	}

	public function test_empty_response_produces_empty_array(): void {
		$this->assertSame( array(), $this->parser->parse( array(), '2026-07-08' ) );
	}
}
