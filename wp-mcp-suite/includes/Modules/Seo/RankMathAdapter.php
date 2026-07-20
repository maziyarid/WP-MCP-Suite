<?php
/**
 * Reads Rank Math's own postmeta. Detection and field names follow Rank
 * Math's documented, stable postmeta keys (the same keys Rank Math itself
 * uses internally and has kept stable across versions). Schema-type
 * detection is intentionally conservative: it reads the "Rich Snippet"
 * primary-type field (rank_math_rich_snippet), which is what Rank Math's
 * own UI shows as the post's configured schema type. It does not attempt
 * to parse the full JSON-LD schema graph Rank Math can generate from
 * blocks, which is a much larger and more fragile surface to reverse-engineer.
 *
 * @package MCPSuite\Modules\Seo
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RankMathAdapter implements SeoMetadataProviderInterface {

	public function is_available(): bool {
		return defined( 'RANK_MATH_VERSION' );
	}

	public function get_snapshot( int $post_id ): SeoSnapshot {
		$title       = (string) get_post_meta( $post_id, 'rank_math_title', true );
		$description = (string) get_post_meta( $post_id, 'rank_math_description', true );
		$focus_kw    = (string) get_post_meta( $post_id, 'rank_math_focus_keyword', true );

		$robots_meta = get_post_meta( $post_id, 'rank_math_robots', true );
		$robots      = is_array( $robots_meta ) ? array_values( array_map( 'strval', $robots_meta ) ) : array();

		$rich_snippet = (string) get_post_meta( $post_id, 'rank_math_rich_snippet', true );
		$schema_types = ( '' !== $rich_snippet && 'off' !== $rich_snippet ) ? array( $rich_snippet ) : array();

		$has_breadcrumbs = class_exists( '\RankMath\Helper' ) && method_exists( '\RankMath\Helper', 'is_module_active' )
			? (bool) \RankMath\Helper::is_module_active( 'breadcrumbs' )
			: false;

		return new SeoSnapshot(
			post_id: $post_id,
			seo_title: $title,
			meta_description: $description,
			focus_keyword: $focus_kw,
			schema_types: $schema_types,
			robots_directives: $robots,
			has_breadcrumbs: $has_breadcrumbs,
			source_plugin: 'rank_math'
		);
	}
}
