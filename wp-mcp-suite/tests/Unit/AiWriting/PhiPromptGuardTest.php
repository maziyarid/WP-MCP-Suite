<?php
/**
 * @package MCPSuite\Tests\Unit\AiWriting
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\AiWriting;

use MCPSuite\Modules\AiWriting\PhiPromptGuard;
use PHPUnit\Framework\TestCase;

final class PhiPromptGuardTest extends TestCase {

	private PhiPromptGuard $guard;

	protected function setUp(): void {
		parent::setUp();
		$this->guard = new PhiPromptGuard();
	}

	public function test_ordinary_marketing_prompt_passes(): void {
		$prompt = 'Write a 500-word blog post about recovery timelines after rhinoplasty, aimed at prospective patients researching the procedure.';
		$this->assertTrue( $this->guard->passes( $prompt ) );
		$this->assertNull( $this->guard->check( $prompt ) );
	}

	public function test_blocks_ssn_like_pattern(): void {
		$reason = $this->guard->check( 'The patient record shows 123-45-6789 on file.' );
		$this->assertNotNull( $reason );
		$this->assertStringContainsString( 'SSN', $reason );
	}

	public function test_blocks_medical_record_number_pattern(): void {
		$reason = $this->guard->check( 'Please summarize the notes for MRN: 8842291.' );
		$this->assertNotNull( $reason );
	}

	public function test_blocks_explicit_patient_identifying_phrasing(): void {
		$this->assertFalse( $this->guard->passes( 'The patient name is included in the attached file.' ) );
		$this->assertFalse( $this->guard->passes( 'Patient presents with mild swelling three days post-op.' ) );
	}

	public function test_blocks_date_of_birth_pattern(): void {
		$reason = $this->guard->check( 'Date of birth: 04/12/1985 should be verified before the appointment.' );
		$this->assertNotNull( $reason );
	}

	public function test_does_not_block_unrelated_use_of_the_word_patient(): void {
		// "patient" alone (without an identifying verb pattern right after
		// it) is common, legitimate marketing copy and must not be blocked
		// wholesale — that would make the guard useless via over-triggering.
		$this->assertTrue( $this->guard->passes( 'Patients often ask how long recovery takes.' ) );
		$this->assertTrue( $this->guard->passes( 'Our patient-first approach means clear communication.' ) );
	}

	public function test_does_not_block_unrelated_dates(): void {
		$this->assertTrue( $this->guard->passes( 'The clinic was founded on 04/12/1985 in Los Angeles.' ) );
	}

	public function test_additional_configured_keyword_phrases_are_blocked_case_insensitively(): void {
		$reason = $this->guard->check( 'A short bio mentioning Jane Q. Example and her recent visit.', array( 'Jane Q. Example' ) );
		$this->assertNotNull( $reason );

		$this->assertFalse( $this->guard->passes( 'a short bio mentioning jane q. example', array( 'Jane Q. Example' ) ) );
	}

	public function test_empty_keyword_phrases_are_ignored_not_matched_against_everything(): void {
		$this->assertTrue( $this->guard->passes( 'Any ordinary prompt text here.', array( '', '   ' ) ) );
	}

	public function test_check_returns_null_exactly_when_passes_returns_true(): void {
		$clean = 'Write an SEO-optimized meta description for our rhinoplasty service page.';
		$dirty = 'MRN: 12345678';

		$this->assertNull( $this->guard->check( $clean ) );
		$this->assertTrue( $this->guard->passes( $clean ) );

		$this->assertNotNull( $this->guard->check( $dirty ) );
		$this->assertFalse( $this->guard->passes( $dirty ) );
	}
}
