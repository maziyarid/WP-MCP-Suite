<?php
/**
 * Scans a prompt for patterns that plausibly indicate PHI before it is
 * ever sent to a third-party AI provider. Spec §2 negative requirement:
 * "contains_phi_flag must be forced 0 at generation time by validating the
 * prompt against a configurable blocklist pattern... if it would trip, the
 * job is rejected before any external call is made." This class is that
 * check — AiWritingModule calls it before AiProviderInterface::generate()
 * is ever invoked, not after.
 *
 * This is deliberately a blunt, pattern-based backstop, not a clinical
 * NLP system: it catches obvious structural markers (SSNs, MRNs, DOB-like
 * dates paired with patient language) and configured keyword phrases. It
 * will have false positives (a legitimate blog post mentioning "date of
 * birth of the clinic's founder") and false negatives (PHI phrased in a
 * way no pattern anticipates). It is one layer of defense — the actual
 * policy is "don't feed patient data into prompts in the first place" —
 * not a guarantee. See PHASE5-NOTES.md.
 *
 * @package MCPSuite\Modules\AiWriting
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\AiWriting;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class PhiPromptGuard {

	/**
	 * Regex patterns for structural PHI-like markers. Kept small and
	 * high-precision on purpose — a guard with too many false positives
	 * gets disabled or worked around by frustrated users, which is worse
	 * than a narrower guard that stays on.
	 *
	 * @var string[]
	 */
	private const DEFAULT_STRUCTURAL_PATTERNS = array(
		'/\b\d{3}-\d{2}-\d{4}\b/'                 => 'SSN-like pattern (###-##-####)',
		'/\bMRN[\s:#-]*\d{4,}\b/i'                 => 'medical record number pattern',
		'/\bpatient\s+(name|is|was|presents)\b/i'  => 'explicit patient-identifying phrasing',
		'/\bdate of birth\s*[:\-]?\s*\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}/i' => 'date-of-birth pattern',
	);

	/**
	 * @param string[] $additional_keyword_phrases Site-specific terms to
	 *        additionally block (e.g. real patient names a clinic knows it
	 *        must never let near an AI prompt), configured via Settings.
	 *        Matched case-insensitively as plain substrings, not regex —
	 *        keeping this configuration surface simple and safe for a
	 *        non-technical site owner to edit.
	 *
	 * @return string|null The human-readable reason for the block, or null if the prompt passes.
	 */
	public function check( string $prompt, array $additional_keyword_phrases = array() ): ?string {
		foreach ( self::DEFAULT_STRUCTURAL_PATTERNS as $pattern => $description ) {
			if ( 1 === preg_match( $pattern, $prompt ) ) {
				return $description;
			}
		}

		foreach ( $additional_keyword_phrases as $phrase ) {
			$phrase = trim( $phrase );
			if ( '' !== $phrase && false !== stripos( $prompt, $phrase ) ) {
				return 'blocked keyword configured in Settings';
			}
		}

		return null;
	}

	public function passes( string $prompt, array $additional_keyword_phrases = array() ): bool {
		return null === $this->check( $prompt, $additional_keyword_phrases );
	}
}
