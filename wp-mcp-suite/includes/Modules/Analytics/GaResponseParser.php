<?php
/**
 * Parses a decoded GA4 Data API runReport response into GaReportRow
 * objects. Maps dimension/metric values by header name (using the
 * response's own dimensionHeaders/metricHeaders arrays) rather than
 * assuming a fixed column order, since relying on request-order-equals-
 * response-order is exactly the kind of brittle assumption that breaks
 * quietly when an API evolves.
 *
 * @package MCPSuite\Modules\Analytics
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Analytics;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class GaResponseParser {

	/**
	 * @param array<string,mixed> $decoded_response
	 * @return GaReportRow[]
	 */
	public function parse( array $decoded_response, string $data_date ): array {
		$dimension_index = $this->header_index_map( $decoded_response['dimensionHeaders'] ?? array() );
		$metric_index    = $this->header_index_map( $decoded_response['metricHeaders'] ?? array() );

		$rows = array();

		foreach ( $decoded_response['rows'] ?? array() as $raw_row ) {
			$dimension_values = $raw_row['dimensionValues'] ?? array();
			$metric_values    = $raw_row['metricValues'] ?? array();

			$landing_page  = $this->dimension_value( $dimension_values, $dimension_index, 'landingPage' );
			$source_medium = $this->dimension_value( $dimension_values, $dimension_index, 'sessionSourceMedium' );

			if ( null === $landing_page ) {
				continue; // malformed row missing the primary dimension; skip rather than write a garbage record
			}

			$rows[] = new GaReportRow(
				landing_page: $landing_page,
				data_date: $data_date,
				sessions: (int) $this->metric_value( $metric_values, $metric_index, 'sessions' ),
				users: (int) $this->metric_value( $metric_values, $metric_index, 'totalUsers' ),
				engaged_sessions: (int) $this->metric_value( $metric_values, $metric_index, 'engagedSessions' ),
				engagement_rate: (float) $this->metric_value( $metric_values, $metric_index, 'engagementRate' ),
				conversions: (int) $this->metric_value( $metric_values, $metric_index, 'conversions' ),
				event_count: (int) $this->metric_value( $metric_values, $metric_index, 'eventCount' ),
				source_medium: $source_medium ?? ''
			);
		}

		return $rows;
	}

	/**
	 * @param array<int,array{name?:string}> $headers
	 * @return array<string,int>
	 */
	private function header_index_map( array $headers ): array {
		$map = array();
		foreach ( $headers as $index => $header ) {
			if ( isset( $header['name'] ) ) {
				$map[ $header['name'] ] = $index;
			}
		}
		return $map;
	}

	/**
	 * @param array<int,array{value?:string}> $values
	 * @param array<string,int>               $index_map
	 */
	private function dimension_value( array $values, array $index_map, string $name ): ?string {
		if ( ! isset( $index_map[ $name ] ) || ! isset( $values[ $index_map[ $name ] ]['value'] ) ) {
			return null;
		}
		return (string) $values[ $index_map[ $name ] ]['value'];
	}

	/**
	 * @param array<int,array{value?:string}> $values
	 * @param array<string,int>               $index_map
	 */
	private function metric_value( array $values, array $index_map, string $name ): string {
		if ( ! isset( $index_map[ $name ] ) || ! isset( $values[ $index_map[ $name ] ]['value'] ) ) {
			return '0';
		}
		return (string) $values[ $index_map[ $name ] ]['value'];
	}
}
