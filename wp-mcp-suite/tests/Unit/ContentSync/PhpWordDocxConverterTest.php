<?php
/**
 * @package MCPSuite\Tests\Unit\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\ContentSync;

use MCPSuite\Modules\ContentSync\DocxConversionException;
use MCPSuite\Modules\ContentSync\PhpWordDocxConverter;
use PHPUnit\Framework\TestCase;

final class PhpWordDocxConverterTest extends TestCase {

	public function test_export_throws_a_clear_configuration_error_when_phpword_is_not_installed(): void {
		if ( class_exists( \PhpOffice\PhpWord\PhpWord::class ) ) {
			$this->markTestSkipped( 'phpoffice/phpword is installed in this environment; see test_round_trip_when_phpword_is_available() instead.' );
		}

		$converter = new PhpWordDocxConverter();

		$this->expectException( DocxConversionException::class );
		$this->expectExceptionMessageMatches( '/composer install/' );
		$converter->html_to_docx( '<p>hello</p>', 'Test' );
	}

	/**
	 * This test only runs once `composer install` has actually pulled
	 * phpoffice/phpword — it cannot run in this sandbox (no Packagist
	 * access) and self-skips rather than silently reporting a false pass.
	 * Run this specifically after `composer install` in a real environment
	 * to consider PhpWordDocxConverter verified, per technical
	 * specification §8, item 6.
	 */
	public function test_round_trip_preserves_a_heading_and_a_paragraph_when_phpword_is_available(): void {
		if ( ! class_exists( \PhpOffice\PhpWord\PhpWord::class ) ) {
			$this->markTestSkipped( 'phpoffice/phpword is not installed. Run "composer install" to exercise the real conversion path.' );
		}

		$converter = new PhpWordDocxConverter();

		$html = '<h2>Rhinoplasty Recovery</h2><p>Most patients return to light activity within a week.</p>';

		$docx_bytes = $converter->html_to_docx( $html, 'Recovery Guide' );
		$this->assertNotEmpty( $docx_bytes );
		$this->assertStringStartsWith( 'PK', $docx_bytes, 'a DOCX file is a zip archive and must start with the PK signature' );

		$result = $converter->docx_to_html( $docx_bytes );

		$this->assertStringContainsString( 'Rhinoplasty Recovery', $result->html );
		$this->assertStringContainsString( 'Most patients return to light activity', $result->html );
	}
}
