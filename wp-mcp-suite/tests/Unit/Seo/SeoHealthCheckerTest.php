<?php
/**
 * @package MCPSuite\Tests\Unit\Seo
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\Seo;

use MCPSuite\Modules\Seo\SeoHealthChecker;
use MCPSuite\Modules\Seo\SeoSnapshot;
use PHPUnit\Framework\TestCase;

final class SeoHealthCheckerTest extends TestCase {

	private SeoHealthChecker $checker;

	protected function setUp(): void {
		parent::setUp();
		$this->checker = new SeoHealthChecker();
	}

	private function perfect_snapshot(): SeoSnapshot {
		return new SeoSnapshot(
			post_id: 1,
			seo_title: 'A Well Optimized Title Under Sixty Chars',
			meta_description: str_repeat( 'a', 140 ),
			focus_keyword: 'rhinoplasty recovery',
			schema_types: array( 'MedicalProcedure' ),
			robots_directives: array(),
			has_breadcrumbs: true,
			source_plugin: 'rank_math'
		);
	}

	private function codes( array $issues ): array {
		return array_map( static fn( $i ) => $i->code, $issues );
	}

	public function test_fully_optimized_post_has_no_issues(): void {
		$issues = $this->checker->check( $this->perfect_snapshot(), 800, true );
		$this->assertSame( array(), $issues );
	}

	public function test_flags_missing_title(): void {
		$snapshot = $this->replace_title( $this->perfect_snapshot(), '' );
		$issues   = $this->checker->check( $snapshot, 800, true );
		$this->assertContains( 'missing_title', $this->codes( $issues ) );
	}

	public function test_whitespace_only_title_is_treated_as_missing(): void {
		$snapshot = $this->replace_title( $this->perfect_snapshot(), '   ' );
		$issues   = $this->checker->check( $snapshot, 800, true );
		$this->assertContains( 'missing_title', $this->codes( $issues ) );
	}

	public function test_flags_overly_long_title(): void {
		$snapshot = $this->replace_title( $this->perfect_snapshot(), str_repeat( 'x', 61 ) );
		$issues   = $this->checker->check( $snapshot, 800, true );
		$this->assertContains( 'title_too_long', $this->codes( $issues ) );
	}

	public function test_title_at_exactly_the_limit_is_not_flagged(): void {
		$snapshot = $this->replace_title( $this->perfect_snapshot(), str_repeat( 'x', 60 ) );
		$issues   = $this->checker->check( $snapshot, 800, true );
		$this->assertNotContains( 'title_too_long', $this->codes( $issues ) );
	}

	public function test_flags_missing_description(): void {
		$snapshot = $this->replace_description( $this->perfect_snapshot(), '' );
		$issues   = $this->checker->check( $snapshot, 800, true );
		$this->assertContains( 'missing_description', $this->codes( $issues ) );
	}

	public function test_flags_thin_content_below_threshold(): void {
		$issues = $this->checker->check( $this->perfect_snapshot(), 150, true );
		$this->assertContains( 'thin_content', $this->codes( $issues ) );
	}

	public function test_does_not_flag_thin_content_at_exactly_the_threshold(): void {
		$issues = $this->checker->check( $this->perfect_snapshot(), 300, true );
		$this->assertNotContains( 'thin_content', $this->codes( $issues ) );
	}

	public function test_zero_word_count_does_not_flag_thin_content(): void {
		// A word count of 0 usually means "not yet computed" rather than
		// "genuinely empty" — this must not produce a false positive on
		// every post before content analysis has run.
		$issues = $this->checker->check( $this->perfect_snapshot(), 0, true );
		$this->assertNotContains( 'thin_content', $this->codes( $issues ) );
	}

	public function test_flags_unexpected_noindex_on_content_that_should_be_public(): void {
		$snapshot = new SeoSnapshot( 1, 'Title', str_repeat( 'a', 140 ), 'kw', array( 'Article' ), array( 'noindex' ), true, 'rank_math' );
		$issues   = $this->checker->check( $snapshot, 800, true );
		$this->assertContains( 'unexpected_noindex', $this->codes( $issues ) );
	}

	public function test_does_not_flag_intentional_noindex_on_a_utility_page(): void {
		$snapshot = new SeoSnapshot( 1, 'Title', str_repeat( 'a', 140 ), 'kw', array( 'Article' ), array( 'noindex' ), true, 'rank_math' );
		$issues   = $this->checker->check( $snapshot, 800, false ); // should_be_indexed = false
		$this->assertNotContains( 'unexpected_noindex', $this->codes( $issues ) );
	}

	public function test_flags_a_public_page_that_is_missing_expected_noindex(): void {
		$snapshot = $this->perfect_snapshot(); // not noindexed
		$issues   = $this->checker->check( $snapshot, 800, false ); // but should be noindexed
		$this->assertContains( 'expected_noindex_missing', $this->codes( $issues ) );
	}

	public function test_flags_missing_schema_and_breadcrumbs(): void {
		$snapshot = new SeoSnapshot( 1, 'Title', str_repeat( 'a', 140 ), 'kw', array(), array(), false, 'rank_math' );
		$issues   = $this->checker->check( $snapshot, 800, true );
		$this->assertContains( 'no_schema_override', $this->codes( $issues ) );
		$this->assertContains( 'breadcrumbs_disabled', $this->codes( $issues ) );
	}

	public function test_custom_thin_content_threshold_is_respected(): void {
		$issues = $this->checker->check( $this->perfect_snapshot(), 400, true, 500 );
		$this->assertContains( 'thin_content', $this->codes( $issues ) );
	}

	private function replace_title( SeoSnapshot $s, string $title ): SeoSnapshot {
		return new SeoSnapshot( $s->post_id, $title, $s->meta_description, $s->focus_keyword, $s->schema_types, $s->robots_directives, $s->has_breadcrumbs, $s->source_plugin );
	}

	private function replace_description( SeoSnapshot $s, string $description ): SeoSnapshot {
		return new SeoSnapshot( $s->post_id, $s->seo_title, $description, $s->focus_keyword, $s->schema_types, $s->robots_directives, $s->has_breadcrumbs, $s->source_plugin );
	}
}
