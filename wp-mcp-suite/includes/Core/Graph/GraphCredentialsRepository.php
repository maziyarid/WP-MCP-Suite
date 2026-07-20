<?php
/**
 * Storage/retrieval for the Microsoft Graph app registration this plugin
 * uses to call OneDrive. Deployment model: application permissions
 * (client-credentials flow), not delegated — see technical specification
 * §13, decision 1: cron-driven background sync has no signed-in user to
 * act on behalf of, which application permissions are designed for. This
 * requires an Azure AD app registration in a Microsoft 365 tenant with
 * admin-consented Graph application permissions (typically
 * Files.ReadWrite.All or Sites.ReadWrite.All, scoped to what Content Sync
 * actually needs) — it will NOT work against a personal/consumer OneDrive
 * account, which only supports delegated auth.
 *
 * client_secret is the only field encrypted — tenant_id/client_id/drive_id
 * are identifiers, not secrets, and keeping them in plaintext options makes
 * the admin UI and support/debugging far simpler without weakening security.
 *
 * @package MCPSuite\Core\Graph
 */

declare( strict_types = 1 );

namespace MCPSuite\Core\Graph;

use MCPSuite\Core\Encryption;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GraphCredentialsRepository {

	private const OPTION_KEY = 'mcp_suite_graph_credentials';

	public function get(): ?GraphCredentials {
		$stored = get_option( self::OPTION_KEY, null );

		if ( ! is_array( $stored ) || empty( $stored['tenant_id'] ) || empty( $stored['client_id'] ) || empty( $stored['client_secret_encrypted'] ) ) {
			return null;
		}

		try {
			$client_secret = Encryption::decrypt( (string) $stored['client_secret_encrypted'] );
		} catch ( \Throwable $e ) {
			// Key rotated or corrupted ciphertext: treat as "not configured"
			// rather than fatal, so the admin UI can prompt for re-entry.
			return null;
		}

		return new GraphCredentials(
			tenant_id: (string) $stored['tenant_id'],
			client_id: (string) $stored['client_id'],
			client_secret: $client_secret,
			drive_id: (string) ( $stored['drive_id'] ?? '' ),
			base_folder_path: (string) ( $stored['base_folder_path'] ?? '/MCP Suite Content Mirror' )
		);
	}

	public function save( string $tenant_id, string $client_id, string $client_secret, string $drive_id, string $base_folder_path ): bool {
		return update_option(
			self::OPTION_KEY,
			array(
				'tenant_id'               => sanitize_text_field( $tenant_id ),
				'client_id'               => sanitize_text_field( $client_id ),
				'client_secret_encrypted' => Encryption::encrypt( $client_secret ),
				'drive_id'                => sanitize_text_field( $drive_id ),
				'base_folder_path'        => sanitize_text_field( $base_folder_path ),
			)
		);
	}

	public function is_configured(): bool {
		return null !== $this->get();
	}
}
