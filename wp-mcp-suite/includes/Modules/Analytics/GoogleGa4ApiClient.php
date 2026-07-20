<?php
/**
 * Calls the GA4 Data API's runReport endpoint for one date, with
 * dimensions [landingPage, sessionSourceMedium] and the six metrics
 * wp_mcp_ga_history stores.
 *
 * @package MCPSuite\Modules\Analytics
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Analytics;

use MCPSuite\Core\Google\GoogleAuthException;
use MCPSuite\Core\Google\GoogleAuthService;
use MCPSuite\Core\Http\HttpClientInterface;
use MCPSuite\Core\Http\HttpException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GoogleGa4ApiClient {

	private const API_BASE = 'https://analyticsdata.googleapis.com/v1beta/properties';

	public function __construct(
		private readonly HttpClientInterface $http_client,
		private readonly GoogleAuthService $auth,
		private readonly GaResponseParser $parser = new GaResponseParser()
	) {}

	/**
	 * @return GaReportRow[]
	 * @throws Ga4ApiException
	 */
	public function fetch_day( string $property_id, string $date ): array {
		$url = self::API_BASE . '/' . rawurlencode( $property_id ) . ':runReport';

		$body = wp_json_encode(
			array(
				'dateRanges' => array( array( 'startDate' => $date, 'endDate' => $date ) ),
				'dimensions' => array( array( 'name' => 'landingPage' ), array( 'name' => 'sessionSourceMedium' ) ),
				'metrics'    => array(
					array( 'name' => 'sessions' ),
					array( 'name' => 'totalUsers' ),
					array( 'name' => 'engagedSessions' ),
					array( 'name' => 'engagementRate' ),
					array( 'name' => 'conversions' ),
					array( 'name' => 'eventCount' ),
				),
				'limit'      => 100000,
			)
		);

		try {
			$access_token = $this->auth->get_access_token();
		} catch ( GoogleAuthException $e ) {
			throw new Ga4ApiException( 'GA4 auth failed: ' . $e->getMessage(), $e->http_status(), $e );
		}

		try {
			$response = $this->http_client->request(
				'POST',
				$url,
				array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				$body
			);
		} catch ( HttpException $e ) {
			throw new Ga4ApiException( 'GA4 request failed at the transport level: ' . $e->getMessage(), null, $e );
		}

		if ( ! $response->is_success() ) {
			throw new Ga4ApiException( 'GA4 request was rejected (HTTP ' . $response->status . '): ' . $response->body, $response->status );
		}

		return $this->parser->parse( $response->json(), $date );
	}
}
