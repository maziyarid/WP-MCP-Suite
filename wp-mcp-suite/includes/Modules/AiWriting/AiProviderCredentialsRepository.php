<?php
/**
 * @package MCPSuite\Modules\AiWriting
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\AiWriting;

use MCPSuite\Core\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AiProviderCredentialsRepository {

	private const OPTION_KEY = 'mcp_suite_ai_provider_settings';

	/**
	 * @return array{base_url:string,api_key:string,model:string}|null
	 */
	public function get(): ?array {
		$stored = get_option( self::OPTION_KEY, null );

		if ( ! is_array( $stored ) || empty( $stored['base_url'] ) || empty( $stored['api_key_encrypted'] ) || empty( $stored['model'] ) ) {
			return null;
		}

		try {
			$api_key = Encryption::decrypt( (string) $stored['api_key_encrypted'] );
		} catch ( \Throwable $e ) {
			return null;
		}

		return array(
			'base_url' => (string) $stored['base_url'],
			'api_key'  => $api_key,
			'model'    => (string) $stored['model'],
		);
	}

	public function save( string $base_url, string $api_key, string $model ): bool {
		return update_option(
			self::OPTION_KEY,
			array(
				'base_url'          => untrailingslashit( $base_url ),
				'api_key_encrypted' => Encryption::encrypt( $api_key ),
				'model'             => sanitize_text_field( $model ),
			)
		);
	}

	/**
	 * @return string[]
	 */
	public function get_blocked_keyword_phrases(): array {
		$stored = get_option( 'mcp_suite_ai_phi_blocklist', array() );
		return is_array( $stored ) ? array_values( array_map( 'strval', $stored ) ) : array();
	}

	/**
	 * @param string[] $phrases
	 */
	public function save_blocked_keyword_phrases( array $phrases ): bool {
		$clean = array_values( array_filter( array_map( 'sanitize_text_field', $phrases ), static fn( $p ) => '' !== trim( $p ) ) );
		return update_option( 'mcp_suite_ai_phi_blocklist', $clean );
	}
}
