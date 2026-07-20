<?php
/**
 * @package MCPSuite\Modules\AiWriting
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\AiWriting;

use MCPSuite\Core\AuditLogger;
use MCPSuite\Core\Capabilities;
use MCPSuite\Core\Http\WpHttpClient;
use MCPSuite\Core\RestControllerBase;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AiWritingRestController extends RestControllerBase {

	protected function required_capability(): string {
		return Capabilities::MANAGE_AI_CONTENT;
	}

	protected function routes(): array {
		return array(
			array( 'route' => '/ai-writing/generate', 'methods' => 'POST', 'handler' => 'handle_generate' ),
			array( 'route' => '/ai-writing/approve/(?P<run_id>\d+)', 'methods' => 'POST', 'handler' => 'handle_approve' ),
			array( 'route' => '/ai-writing/reject/(?P<run_id>\d+)', 'methods' => 'POST', 'handler' => 'handle_reject' ),
			array( 'route' => '/ai-writing/pending-review', 'methods' => 'GET', 'handler' => 'handle_pending_review' ),
		);
	}

	public function handle_generate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$title  = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$prompt = (string) $request->get_param( 'prompt' );
		$post_type = sanitize_key( (string) ( $request->get_param( 'post_type' ) ?: 'post' ) );

		if ( '' === trim( $title ) || '' === trim( $prompt ) ) {
			return $this->error( 'mcp_suite_missing_fields', __( 'Both title and prompt are required.', 'wp-mcp-suite' ), 400 );
		}

		$credentials_repo = new AiProviderCredentialsRepository();
		$settings         = $credentials_repo->get();

		if ( null === $settings ) {
			return $this->error( 'mcp_suite_ai_not_configured', __( 'AI provider is not configured yet. Set it up on the Settings screen.', 'wp-mcp-suite' ), 412 );
		}

		$guard        = new PhiPromptGuard();
		$block_reason = $guard->check( $prompt, $credentials_repo->get_blocked_keyword_phrases() );

		if ( null !== $block_reason ) {
			AuditLogger::log( AuditLogger::ACTION_EDIT, 'ai_writing', 'phi_guard', null, false, array( 'reason' => $block_reason ) );
			return $this->error( 'mcp_suite_prompt_blocked', __( 'This prompt was blocked before being sent to the AI provider: ', 'wp-mcp-suite' ) . $block_reason, 422 );
		}

		$provider = new OpenAiCompatibleProvider( new WpHttpClient(), $settings['base_url'], $settings['api_key'], $settings['model'] );

		try {
			$result = $provider->generate( $prompt );
		} catch ( AiProviderException $e ) {
			AuditLogger::log( AuditLogger::ACTION_EDIT, 'ai_writing', 'generation', null, false, array( 'error' => $e->getMessage() ) );
			return $this->error( 'mcp_suite_ai_generation_failed', __( 'AI generation failed: ', 'wp-mcp-suite' ) . $e->getMessage(), 502 );
		}

		$post_id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_content' => wp_kses_post( $result->output_text ),
				'post_status'  => 'draft',
				'post_type'    => $post_type,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $this->error( 'mcp_suite_draft_creation_failed', $post_id->get_error_message(), 500 );
		}

		$repository = new AiRunRepository();
		$run_id     = $repository->create(
			wp_generate_uuid4(),
			$post_id,
			$result->provider,
			$result->model,
			$prompt,
			$result->output_text,
			false // PHI guard passed, or this line would never be reached
		);

		AuditLogger::log( AuditLogger::ACTION_EDIT, 'ai_writing', 'post', $post_id, true, array( 'event' => 'draft_created', 'run_id' => $run_id ) );

		return $this->success(
			array(
				'run_id'  => $run_id,
				'post_id' => $post_id,
				'edit_link' => get_edit_post_link( $post_id, 'raw' ),
			),
			201
		);
	}

	public function handle_approve( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$run_id = (int) $request->get_param( 'run_id' );
		$repository = new AiRunRepository();
		$run    = $repository->find( $run_id );

		if ( null === $run ) {
			return $this->error( 'mcp_suite_run_not_found', __( 'That AI run does not exist.', 'wp-mcp-suite' ), 404 );
		}

		try {
			$repository->approve_and_publish( $run_id, get_current_user_id() );
		} catch ( InvalidStatusTransitionException $e ) {
			return $this->error( 'mcp_suite_invalid_transition', $e->getMessage(), 409 );
		}

		if ( null !== $run->post_id ) {
			wp_update_post( array( 'ID' => $run->post_id, 'post_status' => 'publish' ) );
		}

		AuditLogger::log( AuditLogger::ACTION_EDIT, 'ai_writing', 'post', $run->post_id, true, array( 'event' => 'ai_run_approved_and_published', 'run_id' => $run_id ) );

		return $this->success( array( 'run_id' => $run_id, 'status' => AiRunStatus::PUBLISHED->value ) );
	}

	public function handle_reject( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$run_id     = (int) $request->get_param( 'run_id' );
		$repository = new AiRunRepository();
		$run        = $repository->find( $run_id );

		if ( null === $run ) {
			return $this->error( 'mcp_suite_run_not_found', __( 'That AI run does not exist.', 'wp-mcp-suite' ), 404 );
		}

		try {
			$repository->reject( $run_id, get_current_user_id() );
		} catch ( InvalidStatusTransitionException $e ) {
			return $this->error( 'mcp_suite_invalid_transition', $e->getMessage(), 409 );
		}

		if ( null !== $run->post_id ) {
			wp_trash_post( $run->post_id );
		}

		AuditLogger::log( AuditLogger::ACTION_EDIT, 'ai_writing', 'post', $run->post_id, true, array( 'event' => 'ai_run_rejected', 'run_id' => $run_id ) );

		return $this->success( array( 'run_id' => $run_id, 'status' => AiRunStatus::REJECTED->value ) );
	}

	public function handle_pending_review( WP_REST_Request $request ): WP_REST_Response {
		$runs = ( new AiRunRepository() )->find_pending_review();

		$data = array_map(
			static fn( AiRun $run ) => array(
				'id'      => $run->id,
				'post_id' => $run->post_id,
				'title'   => $run->post_id ? get_the_title( $run->post_id ) : '',
				'status'  => $run->status->value,
				'provider' => $run->provider,
			),
			$runs
		);

		return $this->success( $data );
	}
}
