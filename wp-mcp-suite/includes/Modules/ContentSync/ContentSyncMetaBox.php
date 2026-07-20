<?php
/**
 * Adds an "Opt into Content Sync" meta box to the post edit screen. This is
 * the UI half of the phi_excluded gate: SyncOrchestrator never mirrors a
 * post until a human with MANAGE_CONTENT explicitly opts it in here, and
 * the checkbox copy says exactly what that means rather than being a
 * generic "enable" toggle.
 *
 * @package MCPSuite\Modules\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\ContentSync;

use MCPSuite\Core\AuditLogger;
use MCPSuite\Core\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ContentSyncMetaBox {

	private const NONCE_ACTION = 'mcp_suite_opt_in';
	private const NONCE_FIELD  = 'mcp_suite_opt_in_nonce';

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_box' ) );
		add_action( 'admin_post_mcp_suite_opt_in', array( $this, 'handle_submit' ) );
	}

	public function add_box(): void {
		if ( ! current_user_can( Capabilities::MANAGE_CONTENT ) ) {
			return;
		}

		foreach ( get_post_types( array( 'public' => true ) ) as $post_type ) {
			add_meta_box(
				'mcp-suite-content-sync',
				__( 'MCP Suite: Content Sync', 'wp-mcp-suite' ),
				array( $this, 'render_box' ),
				$post_type,
				'side'
			);
		}
	}

	public function render_box( \WP_Post $post ): void {
		$repository = new WpdbContentMapRepository();
		$record     = $repository->find_by_post_id( $post->ID );

		$opted_in = $record && ! $record->phi_excluded;

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<input type="hidden" name="post_id" value="' . esc_attr( (string) $post->ID ) . '">';
		echo '<input type="hidden" name="action" value="mcp_suite_opt_in">';

		if ( $opted_in ) {
			echo '<p>' . esc_html__( 'This post is mirrored to Word/OneDrive.', 'wp-mcp-suite' ) . '</p>';
			if ( $record->mirror_status === 'conflict' ) {
				echo '<p style="color:#a00;">' . esc_html__( 'Sync conflict — this post and its OneDrive mirror both changed. Review on the Content Sync screen.', 'wp-mcp-suite' ) . '</p>';
			}
			return;
		}

		echo '<p>' . esc_html__( 'Not mirrored yet. Only opt in content that contains no patient health information.', 'wp-mcp-suite' ) . '</p>';
		submit_button( __( 'Opt into Content Sync', 'wp-mcp-suite' ), 'secondary', 'mcp_suite_opt_in_submit', false );
	}

	public function handle_submit(): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'wp-mcp-suite' ) );
		}

		if ( ! current_user_can( Capabilities::MANAGE_CONTENT ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'wp-mcp-suite' ) );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post    = $post_id ? get_post( $post_id ) : null;

		if ( ! $post ) {
			wp_die( esc_html__( 'That post does not exist.', 'wp-mcp-suite' ) );
		}

		( new WpdbContentMapRepository() )->opt_in( $post_id, $post->post_type );

		AuditLogger::log(
			AuditLogger::ACTION_EDIT,
			'content_sync',
			'post',
			$post_id,
			true,
			array( 'event' => 'phi_excluded_opt_in_via_meta_box' )
		);

		wp_safe_redirect( add_query_arg( 'mcp_suite_opted_in', '1', get_edit_post_link( $post_id, 'raw' ) ) );
		exit;
	}
}
