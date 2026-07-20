<?php
/**
 * @package MCPSuite\Tests\Unit\Graph\Fakes
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\Graph\Fakes;

use MCPSuite\Core\Graph\GraphHttpClientInterface;
use MCPSuite\Core\Graph\GraphHttpResponse;

final class FakeGraphHttpClient implements GraphHttpClientInterface {

	/** @var GraphHttpResponse[] */
	private array $queued_responses;

	/** @var array{method:string,url:string,headers:array<string,string>,body:?string}[] */
	public array $recorded_requests = array();

	public function __construct( GraphHttpResponse ...$queued_responses ) {
		$this->queued_responses = $queued_responses;
	}

	public function request( string $method, string $url, array $headers = array(), ?string $body = null ): GraphHttpResponse {
		$this->recorded_requests[] = array(
			'method'  => $method,
			'url'     => $url,
			'headers' => $headers,
			'body'    => $body,
		);

		if ( empty( $this->queued_responses ) ) {
			throw new \RuntimeException( 'FakeGraphHttpClient: no queued response left for request #' . count( $this->recorded_requests ) );
		}

		return array_shift( $this->queued_responses );
	}

	public function request_count(): int {
		return count( $this->recorded_requests );
	}
}
