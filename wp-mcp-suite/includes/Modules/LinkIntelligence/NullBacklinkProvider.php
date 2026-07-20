<?php
/**
 * Default backlink provider: always reports "not configured" and returns
 * no data. This is not a placeholder bug — it is the correct behavior
 * until BacklinkProviderInterface has a real implementation for whichever
 * provider gets chosen (see that interface's docblock). The Link
 * Intelligence admin page shows this state explicitly rather than
 * pretending backlink data exists.
 *
 * @package MCPSuite\Modules\LinkIntelligence
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\LinkIntelligence;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class NullBacklinkProvider implements BacklinkProviderInterface {

	public function is_configured(): bool {
		return false;
	}

	public function fetch_for_domain( string $domain ): array {
		return array();
	}
}
