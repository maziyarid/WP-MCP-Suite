<?php
/**
 * @package MCPSuite\Tests\Unit\LinkIntelligence
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\LinkIntelligence;

use MCPSuite\Modules\LinkIntelligence\InternalLinkExtractor;
use PHPUnit\Framework\TestCase;

final class InternalLinkExtractorTest extends TestCase {

	private InternalLinkExtractor $extractor;

	protected function setUp(): void {
		parent::setUp();
		$this->extractor = new InternalLinkExtractor();
	}

	public function test_extracts_an_absolute_internal_link(): void {
		$html  = '<p>Read more about <a href="https://drbastaninejad.com/rhinoplasty/">rhinoplasty recovery</a>.</p>';
		$links = $this->extractor->extract( $html, 'https://drbastaninejad.com/blog/post-1/', 'drbastaninejad.com' );

		$this->assertCount( 1, $links );
		$this->assertSame( 'https://drbastaninejad.com/rhinoplasty/', $links[0]->target_url );
		$this->assertSame( 'rhinoplasty recovery', $links[0]->anchor_text );
		$this->assertSame( 'contextual', $links[0]->link_type );
	}

	public function test_resolves_a_root_relative_link(): void {
		$html  = '<a href="/services/">Services</a>';
		$links = $this->extractor->extract( $html, 'https://drbastaninejad.com/blog/post-1/', 'drbastaninejad.com' );

		$this->assertCount( 1, $links );
		$this->assertSame( 'https://drbastaninejad.com/services/', $links[0]->target_url );
	}

	public function test_skips_external_links(): void {
		$html  = '<a href="https://example.com/other-site/">External</a>';
		$links = $this->extractor->extract( $html, 'https://drbastaninejad.com/blog/post-1/', 'drbastaninejad.com' );

		$this->assertSame( array(), $links );
	}

	public function test_www_prefix_is_treated_as_the_same_host(): void {
		$html  = '<a href="https://www.drbastaninejad.com/services/">Services</a>';
		$links = $this->extractor->extract( $html, 'https://drbastaninejad.com/blog/post-1/', 'drbastaninejad.com' );

		$this->assertCount( 1, $links );
	}

	public function test_skips_anchor_only_mailto_and_tel_links(): void {
		$html  = '<a href="#section-2">Jump</a> <a href="mailto:info@drbastaninejad.com">Email</a> <a href="tel:+15551234567">Call</a>';
		$links = $this->extractor->extract( $html, 'https://drbastaninejad.com/blog/post-1/', 'drbastaninejad.com' );

		$this->assertSame( array(), $links );
	}

	public function test_skips_document_relative_paths_rather_than_guessing_resolution(): void {
		$html  = '<a href="other-post/">Relative</a>';
		$links = $this->extractor->extract( $html, 'https://drbastaninejad.com/blog/post-1/', 'drbastaninejad.com' );

		$this->assertSame( array(), $links );
	}

	public function test_captures_a_context_snippet_from_the_surrounding_paragraph(): void {
		$html  = '<p>Before the link, some context. <a href="/target/">click here</a> after the link.</p>';
		$links = $this->extractor->extract( $html, 'https://drbastaninejad.com/x/', 'drbastaninejad.com' );

		$this->assertStringContainsString( 'Before the link, some context.', $links[0]->context_snippet );
		$this->assertStringContainsString( 'after the link.', $links[0]->context_snippet );
	}

	public function test_persian_farsi_anchor_text_and_content_survive_utf8_round_trip(): void {
		// This plugin's primary real-world sites are Persian/RTL — a UTF-8
		// mishandling bug here would silently corrupt every anchor text on
		// those sites, so this is a real correctness requirement, not a
		// nice-to-have.
		$html  = '<p>برای اطلاعات بیشتر به <a href="/رینوپلاستی/">صفحه رینوپلاستی</a> مراجعه کنید.</p>';
		$links = $this->extractor->extract( $html, 'https://drbastaninejad.com/blog/1/', 'drbastaninejad.com' );

		$this->assertCount( 1, $links );
		$this->assertSame( 'صفحه رینوپلاستی', $links[0]->anchor_text );
		$this->assertStringContainsString( 'برای اطلاعات بیشتر', $links[0]->context_snippet );
	}

	public function test_multiple_links_are_all_extracted_in_document_order(): void {
		$html  = '<a href="/one/">One</a><a href="/two/">Two</a><a href="https://external.com/">Three</a>';
		$links = $this->extractor->extract( $html, 'https://drbastaninejad.com/x/', 'drbastaninejad.com' );

		$this->assertCount( 2, $links );
		$this->assertSame( 'https://drbastaninejad.com/one/', $links[0]->target_url );
		$this->assertSame( 'https://drbastaninejad.com/two/', $links[1]->target_url );
	}

	public function test_empty_html_produces_no_links(): void {
		$this->assertSame( array(), $this->extractor->extract( '', 'https://drbastaninejad.com/x/', 'drbastaninejad.com' ) );
	}

	public function test_malformed_html_does_not_throw(): void {
		$html  = '<p>Unclosed <a href="/target/">link<p>Another paragraph';
		$links = $this->extractor->extract( $html, 'https://drbastaninejad.com/x/', 'drbastaninejad.com' );

		$this->assertNotEmpty( $links );
	}

	public function test_long_context_is_truncated_with_ellipsis(): void {
		$long_text = str_repeat( 'word ', 60 );
		$html      = '<p>' . $long_text . '<a href="/target/">link</a></p>';
		$links     = $this->extractor->extract( $html, 'https://drbastaninejad.com/x/', 'drbastaninejad.com' );

		$this->assertLessThanOrEqual( 161, mb_strlen( $links[0]->context_snippet ) );
		$this->assertStringEndsWith( '…', $links[0]->context_snippet );
	}
}
