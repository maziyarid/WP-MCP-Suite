<?php
/**
 * Calls the Search Console searchAnalytics.query endpoint for exactly one
 * date at a time (dimensions page/query/country/device), so each call maps
 * 1:1 onto one day's worth of rows in wp_mcp_gsc_history.
 *
 * @package MCPSuite\Modules\SearchConsole
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\SearchConsole;

use MCPSuite\Core\Google\GoogleAuthException;
use MCPSuite\Core\Google\GoogleAuthService;
use MCPSuite\Core\Http\HttpClientInterface;
use MCPSuite\Core\Http\HttpException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GoogleGscApiClient {

	private const API_BASE = 'https://www.googleapis.com/webmasters/v3/sites';

	public function __construct(
		private readonly HttpClientInterface $http_client,
		private readonly GoogleAuthService $auth,
		private readonly GscResponseParser $parser = new GscResponseParser(),
		private readonly string $row_limit_cap = '25000'
	) {}

	/**
	 * @return GscQueryRow[]
	 * @throws GscApiException
	 */
	public function fetch_day( string $site_url, string $date ): array {
		$url = self::API_BASE . '/' . rawurlencode( $site_url ) . '/searchAnalytics/query';

		$body = wp_json_encode(
			array(
				'startDate'  => $date,
				'endDate'    => $date,
				'dimensions' => array( 'page', 'query', 'country', 'device' ),
				'rowLimit'   => (int) $this->row_limit_cap,
			)
		);

		try {
			$access_token = $this->auth->get_access_token();
		} catch ( GoogleAuthException $e ) {
			throw new GscApiException( 'Search Console auth failed: ' . $e->getMessage(), $e->http_status(), $e );
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
			throw new GscApiException( 'Search Console request failed at the transport level: ' . $e->getMessage(), null, $e );
		}

		if ( ! $response->is_success() ) {
			throw new GscApiException( 'Search Console request was rejected (HTTP ' . $response->status . '): ' . $response->body, $response->status );
		}

		return $this->parser->parse( $response->json(), $date );
	}
}
