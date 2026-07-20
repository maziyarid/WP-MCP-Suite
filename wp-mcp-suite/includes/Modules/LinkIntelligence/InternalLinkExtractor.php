<?php
/**
 * Extracts internal links from a piece of rendered HTML (post content,
 * already run through the_content filters per spec §3.6 — "rendered HTML,
 * not raw post content, so shortcodes/blocks resolve correctly").
 *
 * Only links inside the content area are found this way, so every link
 * this class returns is classified 'contextual' — nav/footer links live in
 * the theme template, not post content, and crawling those would require a
 * full rendered-page fetch rather than just the_content output. That's a
 * documented scope limit, not a bug: see PHASE4-NOTES.md.
 *
 * URL resolution handles absolute URLs and root-relative paths ("/foo/").
 * Document-relative paths ("../foo", "foo/bar" without a leading slash)
 * are NOT resolved and are skipped — WordPress content almost always uses
 * one of the two handled forms, and guessing at relative resolution
 * without knowing the true base path would risk recording a wrong URL,
 * which is worse than skipping it.
 *
 * @package MCPSuite\Modules\LinkIntelligence
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\LinkIntelligence;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class InternalLinkExtractor {

	private const CONTEXT_SNIPPET_MAX_LENGTH = 160;

	/**
	 * @return InternalLink[]
	 */
	public function extract( string $html, string $source_url, string $site_host ): array {
		if ( '' === trim( $html ) ) {
			return array();
		}

		$dom = new \DOMDocument();

		$previous_setting = libxml_use_internal_errors( true );
		// Wrapping in a minimal HTML shell with an explicit UTF-8 meta tag
		// avoids DOMDocument mis-decoding multi-byte (e.g. Persian/Farsi)
		// content, which this plugin's primary sites are full of.
		$dom->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_setting );

		$site_host = $this->normalize_host( $site_host );
		$links     = array();

		foreach ( $dom->getElementsByTagName( 'a' ) as $anchor ) {
			$href = trim( (string) $anchor->getAttribute( 'href' ) );

			$resolved = $this->resolve( $href, $source_url );
			if ( null === $resolved ) {
				continue;
			}

			$target_host = $this->normalize_host( (string) ( parse_url( $resolved, PHP_URL_HOST ) ?: '' ) );

			if ( '' === $target_host || $target_host !== $site_host ) {
				continue; // external, mailto:, tel:, anchor-only, or unresolvable — not an internal link
			}

			$anchor_text = trim( preg_replace( '/\s+/', ' ', $anchor->textContent ) ?? '' );

			$links[] = new InternalLink(
				source_url: $source_url,
				target_url: $resolved,
				anchor_text: $anchor_text,
				link_type: 'contextual',
				context_snippet: $this->context_snippet( $anchor )
			);
		}

		return $links;
	}

	private function resolve( string $href, string $source_url ): ?string {
		if ( '' === $href || str_starts_with( $href, '#' ) ) {
			return null;
		}

		if ( str_starts_with( $href, 'mailto:' ) || str_starts_with( $href, 'tel:' ) || str_starts_with( $href, 'javascript:' ) ) {
			return null;
		}

		if ( str_starts_with( $href, 'http://' ) || str_starts_with( $href, 'https://' ) ) {
			return $href;
		}

		if ( str_starts_with( $href, '//' ) ) {
			$scheme = parse_url( $source_url, PHP_URL_SCHEME ) ?: 'https';
			return $scheme . ':' . $href;
		}

		if ( str_starts_with( $href, '/' ) ) {
			$scheme = (string) ( parse_url( $source_url, PHP_URL_SCHEME ) ?: '' );
			$host   = (string) ( parse_url( $source_url, PHP_URL_HOST ) ?: '' );

			if ( '' === $scheme || '' === $host ) {
				return null;
			}

			return $scheme . '://' . $host . $href;
		}

		return null; // document-relative path — not resolved, see class docblock
	}

	private function normalize_host( string $host ): string {
		$host = strtolower( $host );
		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	private function context_snippet( \DOMNode $anchor ): string {
		$parent = $anchor->parentNode;
		$text   = $parent ? trim( preg_replace( '/\s+/', ' ', $parent->textContent ) ?? '' ) : '';

		return mb_strlen( $text ) > self::CONTEXT_SNIPPET_MAX_LENGTH
			? mb_substr( $text, 0, self::CONTEXT_SNIPPET_MAX_LENGTH ) . '…'
			: $text;
	}
}
