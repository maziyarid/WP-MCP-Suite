<?php
/**
 * @package MCPSuite\Modules\Seo
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Seo;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class SeoSnapshot {

	/**
	 * @param string[] $schema_types
	 * @param string[] $robots_directives e.g. ['noindex'], ['index','follow']
	 */
	public function __construct(
		public readonly int $post_id,
		public readonly string $seo_title,
		public readonly string $meta_description,
		public readonly string $focus_keyword,
		public readonly array $schema_types,
		public readonly array $robots_directives,
		public readonly bool $has_breadcrumbs,
		public readonly string $source_plugin
	) {}

	public function is_noindex(): bool {
		return in_array( 'noindex', $this->robots_directives, true );
	}
}
