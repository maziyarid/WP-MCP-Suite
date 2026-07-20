<?php
/**
 * Reads Yoast SEO's own postmeta. Schema-type detection reads only the
 * per-post override fields (_yoast_wpseo_schema_page_type /
 * _yoast_wpseo_schema_article_type); if a post has no override, Yoast
 * still applies a site-wide default schema type that this class does not
 * attempt to resolve, so an empty result here means "no page-level
 * override," not "no schema at all" — the health checker's message
 * reflects that distinction rather than overclaiming.
 *
 * @package MCPSuite\Modules\Seo
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YoastAdapter implements SeoMetadataProviderInterface {

	public function is_available(): bool {
		return defined( 'WPSEO_VERSION' );
	}

	public function get_snapshot( int $post_id ): SeoSnapshot {
		$title       = (string) get_post_meta( $post_id, '_yoast_wpseo_title', true );
		$description = (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
		$focus_kw    = (string) get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );

		$robots = array();
		if ( '1' === get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ) ) {
			$robots[] = 'noindex';
		}
		if ( '1' === get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true ) ) {
			$robots[] = 'nofollow';
		}

		$schema_types = array_values( array_filter( array(
			(string) get_post_meta( $post_id, '_yoast_wpseo_schema_page_type', true ),
			(string) get_post_meta( $post_id, '_yoast_wpseo_schema_article_type', true ),
		) ) );

		$titles_option   = get_option( 'wpseo_titles', array() );
		$has_breadcrumbs = is_array( $titles_option ) && ! empty( $titles_option['breadcrumbs-enable'] );

		return new SeoSnapshot(
			post_id: $post_id,
			seo_title: $title,
			meta_description: $description,
			focus_keyword: $focus_kw,
			schema_types: $schema_types,
			robots_directives: $robots,
			has_breadcrumbs: $has_breadcrumbs,
			source_plugin: 'yoast'
		);
	}
}
