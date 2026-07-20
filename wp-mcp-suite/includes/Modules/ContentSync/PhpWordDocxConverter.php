<?php
/**
 * PHPWord-backed implementation of DocxConverterInterface.
 *
 * IMPORTANT — read before touching this file: this class depends on
 * phpoffice/phpword (declared in composer.json). The sandbox this plugin
 * was built in has no access to Packagist, so this class has been
 * syntax-checked (php -l) but has NOT been executed against the real
 * library. Run `composer install`, then `composer test:unit` — specifically
 * PhpWordDocxConverterTest, which self-skips if the library isn't present —
 * in a real environment before this is treated as verified, per the
 * "definition of done" in the technical specification, §8, item 6.
 *
 * Export (HTML -> DOCX) uses \PhpOffice\PhpWord\Shared\Html::addHtml(),
 * PHPWord's long-stable HTML import API. Import (DOCX -> HTML) has no
 * equivalent single-call API in PHPWord (unlike JS's mammoth.js, which the
 * original draft referenced) — PHPWord's DOCX reader produces its own
 * Section/Element object tree, which this class walks and renders to a
 * restricted, safe HTML subset itself. This is the "Mammoth-style
 * semantic conversion, not pixel-exact" approach the spec calls for,
 * implemented natively in PHP rather than shelling out to a Node process.
 *
 * @package MCPSuite\Modules\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\ContentSync;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Element\Link;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\PageBreak;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Html as PhpWordHtml;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class PhpWordDocxConverter implements DocxConverterInterface {

	public function html_to_docx( string $html, string $title ): string {
		if ( ! class_exists( PhpWord::class ) ) {
			throw new DocxConversionException( 'phpoffice/phpword is not installed. Run "composer install" in the plugin directory.' );
		}

		$document = new PhpWord();
		$document->getDocInfo()->setTitle( $title );

		$section = $document->addSection();

		try {
			// PHPWord's HTML importer expects a full-ish HTML fragment; it
			// tolerates a bare fragment (no <html>/<body>) fine in practice.
			PhpWordHtml::addHtml( $section, $html, false, false );
		} catch ( \Throwable $e ) {
			throw new DocxConversionException( 'Failed to convert post HTML to DOCX: ' . $e->getMessage(), 0, $e );
		}

		$tmp_path = tempnam( sys_get_temp_dir(), 'mcp-suite-export-' );

		try {
			$writer = IOFactory::createWriter( $document, 'Word2007' );
			$writer->save( $tmp_path );

			$bytes = file_get_contents( $tmp_path );

			if ( false === $bytes ) {
				throw new DocxConversionException( 'DOCX writer produced a file that could not be read back.' );
			}

			return $bytes;
		} finally {
			if ( file_exists( $tmp_path ) ) {
				unlink( $tmp_path );
			}
		}
	}

	public function docx_to_html( string $docx_bytes ): DocxImportResult {
		if ( ! class_exists( PhpWord::class ) ) {
			throw new DocxConversionException( 'phpoffice/phpword is not installed. Run "composer install" in the plugin directory.' );
		}

		$tmp_path = tempnam( sys_get_temp_dir(), 'mcp-suite-import-' );
		$written  = file_put_contents( $tmp_path, $docx_bytes );

		if ( false === $written ) {
			throw new DocxConversionException( 'Could not write uploaded DOCX to a temporary file for parsing.' );
		}

		try {
			$document = IOFactory::load( $tmp_path, 'Word2007' );
		} catch ( \Throwable $e ) {
			throw new DocxConversionException( 'Failed to parse the DOCX file: ' . $e->getMessage(), 0, $e );
		} finally {
			unlink( $tmp_path );
		}

		$html      = '';
		$warnings  = array();

		foreach ( $document->getSections() as $section ) {
			$html .= $this->render_container( $section, $warnings );
		}

		return new DocxImportResult( $html, $warnings );
	}

	/**
	 * @param string[] $warnings
	 */
	private function render_container( AbstractContainer $container, array &$warnings ): string {
		$html = '';

		foreach ( $container->getElements() as $element ) {
			$html .= $this->render_element( $element, $warnings );
		}

		return $html;
	}

	/**
	 * @param string[] $warnings
	 */
	private function render_element( object $element, array &$warnings ): string {
		return match ( true ) {
			$element instanceof Title      => sprintf( '<h%1$d>%2$s</h%1$d>', min( 6, max( 1, $element->getDepth() ) ), esc_html( $element->getText() ) ),
			$element instanceof TextRun    => '<p>' . $this->render_container( $element, $warnings ) . '</p>',
			$element instanceof Text       => esc_html( $element->getText() ),
			$element instanceof Link       => '<a href="' . esc_url( $element->getSource() ) . '">' . esc_html( $element->getText() ?: $element->getSource() ) . '</a>',
			$element instanceof ListItem   => '<li>' . esc_html( $element->getTextObject()->getText() ) . '</li>',
			$element instanceof Table      => $this->render_table( $element, $warnings ),
			$element instanceof Image      => $this->render_image_placeholder( $warnings ),
			$element instanceof TextBreak, $element instanceof PageBreak => '<br>',
			default => $this->record_unsupported( $element, $warnings ),
		};
	}

	/**
	 * @param string[] $warnings
	 */
	private function render_table( Table $table, array &$warnings ): string {
		$html = '<table>';

		foreach ( $table->getRows() as $row ) {
			$html .= '<tr>';
			foreach ( $row->getCells() as $cell ) {
				$html .= '<td>' . $this->render_container( $cell, $warnings ) . '</td>';
			}
			$html .= '</tr>';
		}

		return $html . '</table>';
	}

	/**
	 * @param string[] $warnings
	 */
	private function render_image_placeholder( array &$warnings ): string {
		// Embedded DOCX images are not auto-extracted to the WordPress
		// media library in this version — extracting and re-hosting media
		// safely (dedupe, alt text, size limits) is scoped to a later
		// hardening pass rather than guessed at here. The editor gets an
		// explicit, visible placeholder instead of a silently dropped image.
		$warnings[] = __( 'An embedded image was found and was not imported automatically; add it manually via the Media Library.', 'wp-mcp-suite' );
		return '<p><em>' . esc_html__( '[Image omitted — add manually]', 'wp-mcp-suite' ) . '</em></p>';
	}

	/**
	 * @param string[] $warnings
	 */
	private function record_unsupported( object $element, array &$warnings ): string {
		$warnings[] = sprintf(
			/* translators: %s: PHP class name of the unsupported DOCX element */
			__( 'An unsupported Word element (%s) was normalized to plain text or removed during import.', 'wp-mcp-suite' ),
			get_class( $element )
		);
		return '';
	}
}
