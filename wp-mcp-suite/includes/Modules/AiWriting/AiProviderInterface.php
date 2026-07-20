<?php
/**
 * Pluggable adapter for an AI content-generation provider. Per technical
 * specification §13 decision 3, the original draft named "Blackbox or
 * You.com API" without confirming which is actually available/desired —
 * that is a product-owner decision (API access, pricing, quality for
 * medical-adjacent content all vary), so this module does not hardcode
 * either. `OpenAiCompatibleProvider` implements this interface against the
 * widely-adopted OpenAI chat-completions request/response shape, which
 * covers OpenAI itself and the large number of providers (including,
 * commonly, aggregators and many hosted-model services) that expose an
 * OpenAI-compatible endpoint — genuinely usable today, not a placeholder,
 * while still not locking the module to one specific named vendor.
 *
 * @package MCPSuite\Modules\AiWriting
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\AiWriting;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

interface AiProviderInterface {

	/**
	 * @throws AiProviderException
	 */
	public function generate( string $prompt ): AiGenerationResult;
}
