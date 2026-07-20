<?php
/**
 * Converts between rendered post HTML and DOCX bytes. Per spec §"Word
 * formatting requirement": best-effort semantic conversion, not a pixel
 * clone — headings, lists, tables, images, links and inline formatting are
 * preserved; unsupported effects are normalized or reported, never silently
 * dropped without a trace.
 *
 * @package MCPSuite\Modules\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\ContentSync;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

interface DocxConverterInterface {

	/**
	 * @return string Raw DOCX (OOXML zip) bytes.
	 * @throws DocxConversionException
	 */
	public function html_to_docx( string $html, string $title ): string;

	/**
	 * @return DocxImportResult
	 * @throws DocxConversionException
	 */
	public function docx_to_html( string $docx_bytes ): DocxImportResult;
}
