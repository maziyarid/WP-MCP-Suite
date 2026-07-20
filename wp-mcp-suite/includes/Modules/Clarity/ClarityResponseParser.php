<?php
/**
 * Parses Microsoft Clarity's project-live-insights response into per-page
 * ClarityPageMetrics, merging across the multiple metricName groups the
 * API returns in one call.
 *
 * Confirmed against Microsoft's official documentation
 * (learn.microsoft.com/en-us/clarity/setup-and-installation/clarity-data-export-api,
 * accessed 2026): response shape is a JSON array of
 * {"metricName": string, "information": [ {..fields.., "<Dimension>": value}, ... ]}.
 * The "Traffic" metric's information-object fields are confirmed exactly
 * from the documentation's own sample response: totalSessionCount,
 * totalBotSessionCount, distantUserCount, PagesPerSessionPercentage.
 *
 * NOT confirmed from documentation: the exact information-object field
 * names for the "Dead Click Count", "Rage Click Count", "Quickback Click",
 * and "Scroll Depth" metrics, or the exact key name used for the URL
 * dimension (assumed "URL", matching the capitalization pattern the docs
 * show for the OS dimension). METRIC_FIELD_MAP below isolates every one of
 * these assumptions in one place — verify against one real API response
 * (Settings → Data Export on a live Clarity project) and correct this map
 * if needed; nothing else in this class should need to change.
 *
 * @package MCPSuite\Modules\Clarity
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Clarity;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class ClarityResponseParser {

	private const DIMENSION_KEY = 'URL'; // ASSUMPTION — see class docblock.

	/**
	 * metricName => the information-object field holding that metric's
	 * count/value. "Traffic" is confirmed; the rest are best-effort.
	 */
	private const METRIC_FIELD_MAP = array(
		'Traffic'           => array( 'field' => 'totalSessionCount', 'confirmed' => true ),
		'Rage Click Count'  => array( 'field' => 'RageClickCount', 'confirmed' => false ),
		'Dead Click Count'  => array( 'field' => 'DeadClickCount', 'confirmed' => false ),
		'Quickback Click'   => array( 'field' => 'QuickbackCount', 'confirmed' => false ),
		'Scroll Depth'      => array( 'field' => 'ScrollDepth', 'confirmed' => false ),
	);

	/**
	 * @param array<int,array{metricName?:string,information?:array<int,array<string,mixed>>}> $decoded_response
	 * @return ClarityPageMetrics[]
	 */
	public function parse( array $decoded_response, string $data_date ): array {
		/** @var array<string,array{sessions:int,rage_clicks:int,dead_clicks:int,quick_backs:int,scroll_depths:float[]}> $by_page */
		$by_page = array();

		foreach ( $decoded_response as $metric_group ) {
			$metric_name = (string) ( $metric_group['metricName'] ?? '' );

			if ( ! isset( self::METRIC_FIELD_MAP[ $metric_name ] ) ) {
				continue; // a metric we don't map to a wp_mcp_clarity_history column — ignored, not an error
			}

			$field = self::METRIC_FIELD_MAP[ $metric_name ]['field'];

			foreach ( $metric_group['information'] ?? array() as $entry ) {
				$page_url = (string) ( $entry[ self::DIMENSION_KEY ] ?? '' );
				if ( '' === $page_url ) {
					continue;
				}

				$by_page[ $page_url ] ??= array( 'sessions' => 0, 'rage_clicks' => 0, 'dead_clicks' => 0, 'quick_backs' => 0, 'scroll_depths' => array() );
				$value = isset( $entry[ $field ] ) ? (float) $entry[ $field ] : 0.0;

				match ( $metric_name ) {
					'Traffic'          => $by_page[ $page_url ]['sessions'] = (int) $value,
					'Rage Click Count' => $by_page[ $page_url ]['rage_clicks'] = (int) $value,
					'Dead Click Count' => $by_page[ $page_url ]['dead_clicks'] = (int) $value,
					'Quickback Click'  => $by_page[ $page_url ]['quick_backs'] = (int) $value,
					'Scroll Depth'     => $by_page[ $page_url ]['scroll_depths'][] = $value,
					default            => null,
				};
			}
		}

		$rows = array();
		foreach ( $by_page as $page_url => $metrics ) {
			$avg_scroll = array() !== $metrics['scroll_depths']
				? array_sum( $metrics['scroll_depths'] ) / count( $metrics['scroll_depths'] )
				: 0.0;

			$rows[] = new ClarityPageMetrics(
				page_url: $page_url,
				data_date: $data_date,
				sessions: $metrics['sessions'],
				rage_clicks: $metrics['rage_clicks'],
				dead_clicks: $metrics['dead_clicks'],
				quick_backs: $metrics['quick_backs'],
				avg_scroll_depth: round( $avg_scroll, 2 )
			);
		}

		return $rows;
	}

	/**
	 * @return array<string,bool> metricName => whether its field mapping is confirmed from documentation
	 */
	public static function field_mapping_confidence(): array {
		return array_map( static fn( $entry ) => $entry['confirmed'], self::METRIC_FIELD_MAP );
	}
}
