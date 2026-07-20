<?php
/**
 * Computes SEO health issues for a single post's snapshot. Pure function
 * of its inputs — no WordPress call, no database — so every rule is
 * unit-tested directly rather than only observable through a full
 * WordPress integration test. Cross-post checks (duplicate meta
 * descriptions across the whole site) are NOT here, since they require
 * comparing against other posts; those live in SeoModule, which has the
 * repository this class deliberately does not depend on.
 *
 * @package MCPSuite\Modules\Seo
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Seo;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class SeoHealthChecker {

	public const DEFAULT_THIN_CONTENT_WORD_THRESHOLD = 300;
	public const DEFAULT_TITLE_MAX_LENGTH             = 60;
	public const DEFAULT_DESCRIPTION_MAX_LENGTH       = 160;

	/**
	 * @return SeoIssue[]
	 */
	public function check(
		SeoSnapshot $snapshot,
		int $content_word_count,
		bool $should_be_indexed,
		int $thin_content_threshold = self::DEFAULT_THIN_CONTENT_WORD_THRESHOLD
	): array {
		$issues = array();

		if ( '' === trim( $snapshot->seo_title ) ) {
			$issues[] = new SeoIssue( 'missing_title', 'No SEO title is set.' );
		} elseif ( mb_strlen( $snapshot->seo_title ) > self::DEFAULT_TITLE_MAX_LENGTH ) {
			$issues[] = new SeoIssue( 'title_too_long', sprintf( 'SEO title is %d characters; search engines typically truncate past %d.', mb_strlen( $snapshot->seo_title ), self::DEFAULT_TITLE_MAX_LENGTH ) );
		}

		if ( '' === trim( $snapshot->meta_description ) ) {
			$issues[] = new SeoIssue( 'missing_description', 'No meta description is set.' );
		} elseif ( mb_strlen( $snapshot->meta_description ) > self::DEFAULT_DESCRIPTION_MAX_LENGTH ) {
			$issues[] = new SeoIssue( 'description_too_long', sprintf( 'Meta description is %d characters; search engines typically truncate past %d.', mb_strlen( $snapshot->meta_description ), self::DEFAULT_DESCRIPTION_MAX_LENGTH ) );
		}

		if ( '' === trim( $snapshot->focus_keyword ) ) {
			$issues[] = new SeoIssue( 'missing_focus_keyword', 'No focus keyword is set.' );
		}

		if ( $content_word_count > 0 && $content_word_count < $thin_content_threshold ) {
			$issues[] = new SeoIssue( 'thin_content', sprintf( 'Content is %d words, below the %d-word thin-content threshold.', $content_word_count, $thin_content_threshold ) );
		}

		if ( array() === $snapshot->schema_types ) {
			$issues[] = new SeoIssue( 'no_schema_override', 'No page-level schema type override is set (a site-wide default may still apply).' );
		}

		if ( ! $snapshot->has_breadcrumbs ) {
			$issues[] = new SeoIssue( 'breadcrumbs_disabled', 'Breadcrumbs are not enabled.' );
		}

		if ( $snapshot->is_noindex() && $should_be_indexed ) {
			$issues[] = new SeoIssue( 'unexpected_noindex', 'This content is marked noindex but is expected to be publicly indexed.' );
		}

		if ( ! $snapshot->is_noindex() && ! $should_be_indexed ) {
			$issues[] = new SeoIssue( 'expected_noindex_missing', 'This content is expected to be noindex (e.g. a utility or internal page) but is not marked noindex.' );
		}

		return $issues;
	}
}
