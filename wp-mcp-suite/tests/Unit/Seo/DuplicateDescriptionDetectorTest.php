<?php
/**
 * @package MCPSuite\Tests\Unit\Seo
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\Seo;

use MCPSuite\Modules\Seo\DuplicateDescriptionDetector;
use PHPUnit\Framework\TestCase;

final class DuplicateDescriptionDetectorTest extends TestCase {

	private DuplicateDescriptionDetector $detector;

	protected function setUp(): void {
		parent::setUp();
		$this->detector = new DuplicateDescriptionDetector();
	}

	public function test_no_duplicates_produces_no_issues(): void {
		$issues = $this->detector->find( array( 1 => 'first description', 2 => 'second description' ) );
		$this->assertSame( array(), $issues );
	}

	public function test_two_posts_sharing_a_description_are_both_flagged(): void {
		$issues = $this->detector->find( array( 1 => 'same text', 2 => 'same text', 3 => 'different' ) );

		$this->assertArrayHasKey( 1, $issues );
		$this->assertArrayHasKey( 2, $issues );
		$this->assertArrayNotHasKey( 3, $issues );
		$this->assertSame( 'duplicate_description', $issues[1]->code );
	}

	public function test_three_way_duplicate_lists_the_other_two_in_the_message(): void {
		$issues = $this->detector->find( array( 1 => 'x', 2 => 'x', 3 => 'x' ) );

		$this->assertStringContainsString( '2 other post(s)', $issues[1]->message );
		$this->assertStringContainsString( '2', $issues[1]->message );
		$this->assertStringContainsString( '3', $issues[1]->message );
	}

	public function test_empty_descriptions_are_never_flagged_as_duplicates_of_each_other(): void {
		// Two posts both missing a description is a "missing_description"
		// concern (handled by SeoHealthChecker), not a duplicate concern.
		$issues = $this->detector->find( array( 1 => '', 2 => '', 3 => '   ' ) );
		$this->assertSame( array(), $issues );
	}

	public function test_whitespace_variants_of_the_same_text_are_treated_as_duplicates(): void {
		$issues = $this->detector->find( array( 1 => 'same text', 2 => '  same text  ' ) );
		$this->assertCount( 2, $issues );
	}

	public function test_single_post_with_a_unique_description_is_not_flagged(): void {
		$issues = $this->detector->find( array( 1 => 'unique' ) );
		$this->assertSame( array(), $issues );
	}
}
