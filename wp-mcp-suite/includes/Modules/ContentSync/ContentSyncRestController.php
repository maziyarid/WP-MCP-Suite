<?php
/**
 * REST routes for the Content Sync module. Every route requires
 * Capabilities::MANAGE_CONTENT and a valid nonce, enforced structurally by
 * RestControllerBase — this class cannot accidentally expose an
 * unauthenticated route.
 *
 * @package MCPSuite\Modules\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\ContentSync;

use MCPSuite\Core\AuditLogger;
use MCPSuite\Core\Capabilities;
use MCPSuite\Core\Graph\GraphAuthService;
use MCPSuite\Core\Graph\GraphCredentialsRepository;
use MCPSuite\Core\Graph\WpHttpGraphClient;
use MCPSuite\Core\OAuth\WpTransientTokenCache;
use MCPSuite\Core\RestControllerBase;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ContentSyncRestController extends RestControllerBase {

	protected function required_capability(): string {
		return Capabilities::MANAGE_CONTENT;
	}

	protected function routes(): array {
		return array(
			array(
				'route'   => '/content-sync/sync/(?P<post_id>\d+)',
				'methods' => 'POST',
				'handler' => 'handle_sync_now',
				'args'    => array( 'post_id' => array( 'validate_callback' => static fn( $v ) => is_numeric( $v ) ) ),
			),
			array(
				'route'   => '/content-sync/opt-in/(?P<post_id>\d+)',
				'methods' => 'POST',
				'handler' => 'handle_opt_in',
				'args'    => array( 'post_id' => array( 'validate_callback' => static fn( $v ) => is_numeric( $v ) ) ),
			),
			array(
				'route'   => '/content-sync/status',
				'methods' => 'GET',
				'handler' => 'handle_status',
			),
		);
	}

	public function handle_sync_now( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! get_post( $post_id ) ) {
			return $this->error( 'mcp_suite_post_not_found', __( 'That post does not exist.', 'wp-mcp-suite' ), 404 );
		}

		$orchestrator = $this->build_orchestrator();
		if ( is_wp_error( $orchestrator ) ) {
			return $orchestrator;
		}

		$result = $orchestrator->sync_post( $post_id );

		( new SyncResultLogger() )->log( $result );

		return $this->success(
			array(
				'post_id'  => $result->post_id,
				'decision' => $result->decision->value,
				'success'  => $result->success,
				'message'  => $result->message,
				'warnings' => $result->warnings,
			)
		);
	}

	public function handle_opt_in( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return $this->error( 'mcp_suite_post_not_found', __( 'That post does not exist.', 'wp-mcp-suite' ), 404 );
		}

		$repository = new WpdbContentMapRepository();
		$record     = $repository->opt_in( $post_id, $post->post_type );

		AuditLogger::log(
			AuditLogger::ACTION_EDIT,
			'content_sync',
			'post',
			$post_id,
			true,
			array( 'event' => 'phi_excluded_opt_in' )
		);

		return $this->success( array( 'post_id' => $post_id, 'phi_excluded' => $record->phi_excluded ) );
	}

	public function handle_status( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$table = $wpdb->prefix . 'mcp_content_map';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "SELECT post_id, post_type, mirror_status, phi_excluded, last_synced_at FROM {$table} ORDER BY updated_at DESC LIMIT 200", ARRAY_A );

		AuditLogger::log( AuditLogger::ACTION_VIEW, 'content_sync', 'status_list' );

		return $this->success( $rows ?: array() );
	}

	/**
	 * @return SyncOrchestrator|WP_Error
	 */
	private function build_orchestrator() {
		$credentials_repo = new GraphCredentialsRepository();
		$credentials      = $credentials_repo->get();

		if ( null === $credentials ) {
			return $this->error(
				'mcp_suite_graph_not_configured',
				__( 'Microsoft Graph credentials are not configured yet. Set them on the Settings screen first.', 'wp-mcp-suite' ),
				412
			);
		}

		$http_client = new WpHttpGraphClient();
		$auth        = new GraphAuthService( $http_client, new WpTransientTokenCache( 'mcp_suite_graph_token' ), $credentials );
		$onedrive    = new GraphOneDriveProvider( $http_client, $auth, $credentials->drive_id );

		return new SyncOrchestrator(
			new WpContentProvider(),
			$onedrive,
			new PhpWordDocxConverter(),
			new WpdbContentMapRepository(),
			new SyncEngine(),
			$credentials->base_folder_path
		);
	}
}
