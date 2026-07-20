<?php
/**
 * @package MCPSuite\Tests\Unit\Clarity
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\Clarity;

use MCPSuite\Modules\Clarity\ClarityResponseParser;
use PHPUnit\Framework\TestCase;

final class ClarityResponseParserTest extends TestCase {

	private ClarityResponseParser $parser;

	protected function setUp(): void {
		parent::setUp();
		$this->parser = new ClarityResponseParser();
	}

	public function test_parses_the_traffic_metric_using_the_officially_documented_sample_shape(): void {
		// This fixture is structurally identical to Microsoft's own sample
		// response in the Clarity Data Export API documentation, with the
		// "OS" dimension swapped for "URL" per this module's use.
		$response = array(
			array(
				'metricName' => 'Traffic',
				'information' => array(
					array(
						'totalSessionCount'        => '42',
						'totalBotSessionCount'     => '3',
						'distantUserCount'         => '38',
						'PagesPerSessionPercentage' => 1.4,
						'URL'                      => '/rhinoplasty/',
					),
				),
			),
		);

		$rows = $this->parser->parse( $response, '2026-07-08' );

		$this->assertCount( 1, $rows );
		$this->assertSame( '/rhinoplasty/', $rows[0]->page_url );
		$this->assertSame( 42, $rows[0]->sessions );
	}

	public function test_merges_multiple_metric_groups_for_the_same_page(): void {
		$response = array(
			array( 'metricName' => 'Traffic', 'information' => array( array( 'totalSessionCount' => '100', 'URL' => '/p/' ) ) ),
			array( 'metricName' => 'Rage Click Count', 'information' => array( array( 'RageClickCount' => '5', 'URL' => '/p/' ) ) ),
			array( 'metricName' => 'Dead Click Count', 'information' => array( array( 'DeadClickCount' => '2', 'URL' => '/p/' ) ) ),
			array( 'metricName' => 'Quickback Click', 'information' => array( array( 'QuickbackCount' => '1', 'URL' => '/p/' ) ) ),
			array( 'metricName' => 'Scroll Depth', 'information' => array( array( 'ScrollDepth' => '75.5', 'URL' => '/p/' ) ) ),
		);

		$rows = $this->parser->parse( $response, '2026-07-08' );

		$this->assertCount( 1, $rows );
		$this->assertSame( 100, $rows[0]->sessions );
		$this->assertSame( 5, $rows[0]->rage_clicks );
		$this->assertSame( 2, $rows[0]->dead_clicks );
		$this->assertSame( 1, $rows[0]->quick_backs );
		$this->assertEqualsWithDelta( 75.5, $rows[0]->avg_scroll_depth, 0.01 );
	}

	public function test_multiple_pages_are_kept_separate(): void {
		$response = array(
			array(
				'metricName'  => 'Traffic',
				'information' => array(
					array( 'totalSessionCount' => '10', 'URL' => '/a/' ),
					array( 'totalSessionCount' => '20', 'URL' => '/b/' ),
				),
			),
		);

		$rows = $this->parser->parse( $response, '2026-07-08' );

		$this->assertCount( 2, $rows );
		$urls = array_map( static fn( $r ) => $r->page_url, $rows );
		$this->assertContains( '/a/', $urls );
		$this->assertContains( '/b/', $urls );
	}

	public function test_multiple_scroll_depth_entries_for_the_same_page_are_averaged(): void {
		$response = array(
			array(
				'metricName'  => 'Scroll Depth',
				'information' => array(
					array( 'ScrollDepth' => '60', 'URL' => '/p/' ),
					array( 'ScrollDepth' => '80', 'URL' => '/p/' ),
				),
			),
		);

		$rows = $this->parser->parse( $response, '2026-07-08' );

		$this->assertEqualsWithDelta( 70.0, $rows[0]->avg_scroll_depth, 0.01 );
	}

	public function test_unrecognized_metric_names_are_ignored_not_fatal(): void {
		$response = array(
			array( 'metricName' => 'Popular Pages', 'information' => array( array( 'URL' => '/p/', 'somefield' => '1' ) ) ),
		);

		$rows = $this->parser->parse( $response, '2026-07-08' );

		// "Popular Pages" isn't in METRIC_FIELD_MAP, so no page row is
		// created purely from it — this documents current behavior rather
		// than asserting a specific desirable outcome either way.
		$this->assertSame( array(), $rows );
	}

	public function test_entry_missing_the_url_dimension_is_skipped(): void {
		$response = array(
			array( 'metricName' => 'Traffic', 'information' => array( array( 'totalSessionCount' => '5' ) ) ),
		);

		$rows = $this->parser->parse( $response, '2026-07-08' );
		$this->assertSame( array(), $rows );
	}

	public function test_empty_response_produces_empty_array(): void {
		$this->assertSame( array(), $this->parser->parse( array(), '2026-07-08' ) );
	}

	public function test_field_mapping_confidence_flags_traffic_as_confirmed_and_others_as_not(): void {
		$confidence = ClarityResponseParser::field_mapping_confidence();

		$this->assertTrue( $confidence['Traffic'] );
		$this->assertFalse( $confidence['Rage Click Count'] );
		$this->assertFalse( $confidence['Dead Click Count'] );
		$this->assertFalse( $confidence['Quickback Click'] );
		$this->assertFalse( $confidence['Scroll Depth'] );
	}
}
