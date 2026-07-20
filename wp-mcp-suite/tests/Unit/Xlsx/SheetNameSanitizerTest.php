<?php
/**
 * @package MCPSuite\Tests\Unit\Xlsx
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\Xlsx;

use MCPSuite\Core\Xlsx\SheetNameSanitizer;
use PHPUnit\Framework\TestCase;

final class SheetNameSanitizerTest extends TestCase {

	private SheetNameSanitizer $sanitizer;

	protected function setUp(): void {
		parent::setUp();
		$this->sanitizer = new SheetNameSanitizer();
	}

	public function test_ordinary_name_passes_through_unchanged(): void {
		$this->assertSame( 'Summary', $this->sanitizer->sanitize( 'Summary' ) );
	}

	public function test_forbidden_characters_are_stripped(): void {
		$this->assertSame( 'AB', $this->sanitizer->sanitize( 'A:B\\/?*[]' ) );
	}

	public function test_names_longer_than_31_characters_are_truncated(): void {
		$long = str_repeat( 'x', 50 );
		$result = $this->sanitizer->sanitize( $long );

		$this->assertSame( 31, mb_strlen( $result ) );
	}

	public function test_empty_after_stripping_falls_back_to_a_default_name(): void {
		$this->assertSame( 'Sheet', $this->sanitizer->sanitize( '::/\\' ) );
	}

	public function test_multibyte_name_is_truncated_by_character_count_not_byte_count(): void {
		$persian = str_repeat( 'گ', 40 ); // 40 Persian characters, well over 31
		$result  = $this->sanitizer->sanitize( $persian );

		$this->assertSame( 31, mb_strlen( $result ) );
	}
}
