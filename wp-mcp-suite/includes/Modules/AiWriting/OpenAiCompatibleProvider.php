<?php
/**
 * Calls an OpenAI-compatible chat-completions endpoint
 * (POST {base_url}/chat/completions with a "messages" array, reading
 * choices[0].message.content back). base_url, api_key, and model are all
 * configurable — this same class works against OpenAI itself or any
 * provider exposing the same contract, without code changes.
 *
 * @package MCPSuite\Modules\AiWriting
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\AiWriting;

use MCPSuite\Core\Http\HttpClientInterface;
use MCPSuite\Core\Http\HttpException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OpenAiCompatibleProvider implements AiProviderInterface {

	public function __construct(
		private readonly HttpClientInterface $http_client,
		private readonly string $base_url,
		private readonly string $api_key,
		private readonly string $model,
		private readonly float $temperature = 0.7,
		private readonly int $max_tokens = 2000
	) {}

	public function generate( string $prompt ): AiGenerationResult {
		$url = rtrim( $this->base_url, '/' ) . '/chat/completions';

		$body = wp_json_encode(
			array(
				'model'       => $this->model,
				'messages'    => array( array( 'role' => 'user', 'content' => $prompt ) ),
				'temperature' => $this->temperature,
				'max_tokens'  => $this->max_tokens,
			)
		);

		try {
			$response = $this->http_client->request(
				'POST',
				$url,
				array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
				),
				$body
			);
		} catch ( HttpException $e ) {
			throw new AiProviderException( 'AI provider request failed at the transport level: ' . $e->getMessage(), null, $e );
		}

		if ( ! $response->is_success() ) {
			throw new AiProviderException( 'AI provider request was rejected (HTTP ' . $response->status . '): ' . $response->body, $response->status );
		}

		$json = $response->json();

		$content = $json['choices'][0]['message']['content'] ?? null;

		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			throw new AiProviderException( 'AI provider response did not contain usable content at choices[0].message.content.', $response->status );
		}

		return new AiGenerationResult(
			output_text: $content,
			provider: 'openai_compatible',
			model: (string) ( $json['model'] ?? $this->model ),
			prompt_tokens: isset( $json['usage']['prompt_tokens'] ) ? (int) $json['usage']['prompt_tokens'] : null,
			completion_tokens: isset( $json['usage']['completion_tokens'] ) ? (int) $json['usage']['completion_tokens'] : null
		);
	}
}
