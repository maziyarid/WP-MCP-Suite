<?php
/**
 * Converts the (header-row + data-rows) sheet structure ReportDataAggregator
 * produces into normalized JSON — an array of {column: value} objects per
 * sheet, keyed by the header row, rather than raw arrays-of-arrays. This
 * is what makes the export genuinely "Copilot-ready" per spec's wording:
 * a tool reasoning over the JSON doesn't need to already know column order.
 *
 * Pure array transformation — no WordPress, no I/O — so it's tested
 * directly against representative input shapes.
 *
 * @package MCPSuite\Modules\Reporting
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Reporting;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class ReportJsonTransformer {

	/**
	 * @param array<string,array<int,array<int,mixed>>> $sheets sheet name => [header_row, ...data_rows]
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	public function transform( array $sheets ): array {
		$result = array();

		foreach ( $sheets as $sheet_name => $rows ) {
			if ( array() === $rows ) {
				$result[ $sheet_name ] = array();
				continue;
			}

			$header = array_map( 'strval', $rows[0] );
			$data   = array_slice( $rows, 1 );

			$result[ $sheet_name ] = array_map(
				static function ( array $row ) use ( $header ): array {
					$record = array();
					foreach ( $header as $col_index => $col_name ) {
						$record[ $col_name ] = $row[ $col_index ] ?? null;
					}
					return $record;
				},
				$data
			);
		}

		return $result;
	}
}
