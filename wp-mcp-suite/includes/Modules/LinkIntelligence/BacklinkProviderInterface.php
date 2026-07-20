<?php
/**
 * Pluggable adapter for an external backlink data source. Per technical
 * specification §13 decision 2, the original draft named "a chosen
 * free-tier backlink source" without specifying one — that is a real
 * product-owner decision (API terms, pricing, and rate limits vary a lot
 * between providers), not something this build should guess at and lock
 * in silently. Only NullBacklinkProvider is implemented; a real adapter
 * (e.g. for whichever provider is chosen) is a small, focused follow-up
 * once that decision is made — implement this interface, register it in
 * LinkIntelligenceModule, done.
 *
 * @package MCPSuite\Modules\LinkIntelligence
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\LinkIntelligence;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

interface BacklinkProviderInterface {

	public function is_configured(): bool;

	/**
	 * @return Backlink[]
	 */
	public function fetch_for_domain( string $domain ): array;
}
