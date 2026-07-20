<?php
/**
 * @package MCPSuite\Tests\Unit\ContentSync\Fakes
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\ContentSync\Fakes;

use MCPSuite\Modules\ContentSync\DocxConverterInterface;
use MCPSuite\Modules\ContentSync\DocxImportResult;

final class FakeDocxConverter implements DocxConverterInterface {

	public int $export_call_count = 0;
	public int $import_call_count = 0;

	public function html_to_docx( string $html, string $title ): string {
		$this->export_call_count++;
		// Deterministic fake "DOCX bytes" derived from the input, so tests
		// can assert on what was passed through without a real OOXML writer.
		return 'FAKE_DOCX::' . $title . '::' . hash( 'sha256', $html );
	}

	public function docx_to_html( string $docx_bytes ): DocxImportResult {
		$this->import_call_count++;
		return new DocxImportResult( '<p>imported: ' . htmlspecialchars( $docx_bytes, ENT_QUOTES ) . '</p>' );
	}
}
