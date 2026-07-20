<?php
/**
 * @package MCPSuite\Tests\Unit\Http\Fakes
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\Http\Fakes;

use MCPSuite\Core\Http\HttpClientInterface;
use MCPSuite\Core\Http\HttpResponse;

final class FakeHttpClient implements HttpClientInterface {

	/** @var HttpResponse[] */
	private array $queued_responses;

	/** @var array{method:string,url:string,headers:array<string,string>,body:?string}[] */
	public array $recorded_requests = array();

	public function __construct( HttpResponse ...$queued_responses ) {
		$this->queued_responses = $queued_responses;
	}

	public function request( string $method, string $url, array $headers = array(), ?string $body = null ): HttpResponse {
		$this->recorded_requests[] = array(
			'method'  => $method,
			'url'     => $url,
			'headers' => $headers,
			'body'    => $body,
		);

		if ( empty( $this->queued_responses ) ) {
			throw new \RuntimeException( 'FakeHttpClient: no queued response left for request #' . count( $this->recorded_requests ) );
		}

		return array_shift( $this->queued_responses );
	}

	public function request_count(): int {
		return count( $this->recorded_requests );
	}
}
