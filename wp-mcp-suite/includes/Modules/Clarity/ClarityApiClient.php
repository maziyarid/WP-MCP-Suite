<?php
/**
 * Calls Clarity's project-live-insights endpoint. Per Microsoft's
 * documented limits: max 10 requests/project/day, data confined to the
 * previous 1-3 days, max 3 dimensions per request, response capped at
 * 1,000 rows with no pagination. This client makes exactly one request
 * per call — the daily cron (ClarityModule) is responsible for staying
 * well under the 10/day quota by calling this at most once per day.
 *
 * @package MCPSuite\Modules\Clarity
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Clarity;

use MCPSuite\Core\Http\HttpClientInterface;
use MCPSuite\Core\Http\HttpException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ClarityApiClient {

	private const API_URL = 'https://www.clarity.ms/export-data/api/v1/project-live-insights';

	public function __construct(
		private readonly HttpClientInterface $http_client,
		private readonly ClarityResponseParser $parser = new ClarityResponseParser()
	) {}

	/**
	 * @return ClarityPageMetrics[]
	 * @throws ClarityApiException
	 */
	public function fetch_recent( string $api_token, int $num_of_days = 1 ): array {
		if ( $num_of_days < 1 || $num_of_days > 3 ) {
			throw new ClarityApiException( 'numOfDays must be 1, 2, or 3 per Clarity API limits.' );
		}

		$url = self::API_URL . '?' . http_build_query( array( 'numOfDays' => $num_of_days, 'dimension1' => 'URL' ) );

		try {
			$response = $this->http_client->request(
				'GET',
				$url,
				array(
					'Authorization' => 'Bearer ' . $api_token,
					'Content-Type'  => 'application/json',
				)
			);
		} catch ( HttpException $e ) {
			throw new ClarityApiException( 'Clarity request failed at the transport level: ' . $e->getMessage(), null, $e );
		}

		if ( 429 === $response->status ) {
			throw new ClarityApiException( 'Clarity daily request quota (10/project/day) was exceeded.', 429 );
		}

		if ( ! $response->is_success() ) {
			throw new ClarityApiException( 'Clarity request was rejected (HTTP ' . $response->status . '): ' . $response->body, $response->status );
		}

		$decoded = json_decode( $response->body, true );
		if ( ! is_array( $decoded ) ) {
			throw new ClarityApiException( 'Clarity response body was not valid JSON.', $response->status );
		}

		$data_date = gmdate( 'Y-m-d', strtotime( '-1 day' ) ); // numOfDays=1 covers "the last 24 hours," reported as yesterday's date
		return $this->parser->parse( $decoded, $data_date );
	}
}
