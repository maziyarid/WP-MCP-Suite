<?php
/**
 * Parses a decoded Search Console searchAnalytics.query response body into
 * GscQueryRow objects. Kept separate from the HTTP-calling client class so
 * the parsing logic — which is where a real-world API response shape
 * mismatch would actually bite — is directly unit testable against fixed
 * JSON fixtures, without mocking HTTP at all.
 *
 * @package MCPSuite\Modules\SearchConsole
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\SearchConsole;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class GscResponseParser {

	/**
	 * @param array<string,mixed> $decoded_response The JSON-decoded response body.
	 * @param string              $data_date        The single date this query covered (GSC's dimensions here don't include date; the caller queries one date at a time).
	 * @return GscQueryRow[]
	 */
	public function parse( array $decoded_response, string $data_date ): array {
		$rows = array();

		foreach ( $decoded_response['rows'] ?? array() as $raw_row ) {
			$keys = $raw_row['keys'] ?? array();

			// Dimension order is exactly what was requested — the client
			// always requests ['page','query','country','device'], so keys
			// are positional in that fixed order per Google's documented behavior.
			if ( count( $keys ) < 4 ) {
				continue; // malformed row; skip rather than crash the whole batch
			}

			$rows[] = new GscQueryRow(
				page_url: (string) $keys[0],
				query: (string) $keys[1],
				country: (string) $keys[2],
				device: strtolower( (string) $keys[3] ),
				data_date: $data_date,
				clicks: (int) ( $raw_row['clicks'] ?? 0 ),
				impressions: (int) ( $raw_row['impressions'] ?? 0 ),
				ctr: (float) ( $raw_row['ctr'] ?? 0.0 ),
				position: (float) ( $raw_row['position'] ?? 0.0 )
			);
		}

		return $rows;
	}
}
