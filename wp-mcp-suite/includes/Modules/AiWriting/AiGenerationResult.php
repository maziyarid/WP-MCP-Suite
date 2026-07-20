<?php
/**
 * @package MCPSuite\Modules\AiWriting
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\AiWriting;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class AiGenerationResult {

	public function __construct(
		public readonly string $output_text,
		public readonly string $provider,
		public readonly string $model,
		public readonly ?int $prompt_tokens = null,
		public readonly ?int $completion_tokens = null
	) {}
}
