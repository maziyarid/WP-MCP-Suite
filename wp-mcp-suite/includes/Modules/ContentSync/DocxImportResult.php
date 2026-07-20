<?php
/**
 * Spec requirement: "Unsupported Word effects should be normalized or
 * rejected with a report to the editor" — $unsupported_elements is that
 * report. An import with a non-empty report is not a failure (it still
 * produces usable $html), but the admin UI must surface it to the editor
 * rather than silently accepting a degraded conversion.
 *
 * @package MCPSuite\Modules\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\ContentSync;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class DocxImportResult {

	/**
	 * @param string[] $unsupported_elements Human-readable notes about
	 *                                        anything normalized or dropped
	 *                                        during conversion, e.g.
	 *                                        "1 WordArt object was removed".
	 */
	public function __construct(
		public readonly string $html,
		public readonly array $unsupported_elements = array()
	) {}

	public function has_warnings(): bool {
		return array() !== $this->unsupported_elements;
	}
}
