<?php
/**
 * Orchestrates one post's sync tick: load current WP + OneDrive state,
 * consult SyncEngine for the decision, perform the corresponding action,
 * and persist the new content-map state. Every collaborator is injected
 * via interfaces (ContentProviderInterface, OneDriveProviderInterface,
 * DocxConverterInterface, ContentMapRepositoryInterface), so this class —
 * the highest-risk logic in the module, since it decides when data from
 * one system overwrites another — is fully unit-testable with fakes and
 * touches no WordPress function directly.
 *
 * Deliberately does NOT write to wp_mcp_sync_log or the audit log itself;
 * it returns a SyncResult and leaves logging to its caller (ContentSyncModule),
 * which has real WordPress available. This keeps the decision/action logic
 * testable in total isolation from persistence concerns.
 *
 * @package MCPSuite\Modules\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\ContentSync;

use MCPSuite\Core\Graph\GraphException;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class SyncOrchestrator {

	public function __construct(
		private readonly ContentProviderInterface $content_provider,
		private readonly OneDriveProviderInterface $onedrive,
		private readonly DocxConverterInterface $docx_converter,
		private readonly ContentMapRepositoryInterface $repository,
		private readonly SyncEngine $sync_engine,
		private readonly string $base_folder_path
	) {}

	public function sync_post( int $post_id ): SyncResult {
		try {
			$wp_content = $this->content_provider->get_content( $post_id );
		} catch ( \Throwable $e ) {
			return new SyncResult( $post_id, SyncDecision::SKIP, false, 'Could not load post content: ' . $e->getMessage() );
		}

		$record = $this->repository->find_by_post_id( $post_id )
			?? $this->repository->create( $post_id, $wp_content->post_type );

		if ( $record->phi_excluded && null === $record->onedrive_item_id ) {
			// phi_excluded defaults true (see spec §2, "phi_excluded defaults
			// to 1") — a site owner must explicitly opt this content type in
			// before the very first export happens. Once a mirror exists,
			// phi_excluded no longer blocks subsequent ticks; toggling it
			// back on is a data-retention decision, not an ongoing block,
			// and is out of scope for this orchestrator.
			return new SyncResult( $post_id, SyncDecision::SKIP, true, 'Post type is not yet opted into content sync (phi_excluded).' );
		}

		if ( null === $record->onedrive_item_id ) {
			return $this->perform_initial_export( $record, $wp_content );
		}

		try {
			$onedrive_meta = $this->onedrive->get_metadata( $record->onedrive_item_id );
		} catch ( GraphException $e ) {
			return new SyncResult( $post_id, SyncDecision::SKIP, false, 'Failed to read OneDrive mirror state: ' . $e->getMessage() );
		}

		$decision = $this->sync_engine->decide(
			$record->last_export_hash,
			$record->last_import_hash,
			$wp_content->content_hash,
			$onedrive_meta->content_hash
		);

		return match ( $decision ) {
			SyncDecision::SKIP     => new SyncResult( $post_id, $decision, true, 'No changes on either side.' ),
			SyncDecision::EXPORT   => $this->perform_export( $record, $wp_content ),
			SyncDecision::IMPORT   => $this->perform_import( $record, $onedrive_meta ),
			SyncDecision::CONFLICT => $this->flag_conflict( $record ),
		};
	}

	private function perform_initial_export( ContentMapRecord $record, PostContent $wp_content ): SyncResult {
		try {
			$docx_bytes = $this->docx_converter->html_to_docx( $wp_content->rendered_html, $wp_content->title );
			$path       = $this->build_path( $record );
			$item       = $this->onedrive->upload( $path, $docx_bytes );
		} catch ( \Throwable $e ) {
			return new SyncResult( $record->post_id, SyncDecision::EXPORT, false, 'Initial export failed: ' . $e->getMessage() );
		}

		$record->onedrive_item_id = $item->item_id;
		$record->onedrive_path    = $this->build_path( $record );
		$record->mirror_status    = 'synced';
		$record->last_export_hash = $wp_content->content_hash;
		$record->last_import_hash = $item->content_hash;

		$this->repository->save( $record );
		$this->repository->record_version( $record->id, 'export', hash( 'sha256', $docx_bytes ), strlen( $docx_bytes ), $item->graph_version_id );

		return new SyncResult( $record->post_id, SyncDecision::EXPORT, true, 'Initial export to OneDrive completed.' );
	}

	private function perform_export( ContentMapRecord $record, PostContent $wp_content ): SyncResult {
		try {
			$docx_bytes = $this->docx_converter->html_to_docx( $wp_content->rendered_html, $wp_content->title );
			$item       = $this->onedrive->upload( $record->onedrive_path ?? $this->build_path( $record ), $docx_bytes );
		} catch ( \Throwable $e ) {
			return new SyncResult( $record->post_id, SyncDecision::EXPORT, false, 'Export failed: ' . $e->getMessage() );
		}

		$record->mirror_status    = 'synced';
		$record->last_export_hash = $wp_content->content_hash;
		$record->last_import_hash = $item->content_hash;

		$this->repository->save( $record );
		$this->repository->record_version( $record->id, 'export', hash( 'sha256', $docx_bytes ), strlen( $docx_bytes ), $item->graph_version_id );

		return new SyncResult( $record->post_id, SyncDecision::EXPORT, true, 'WordPress changes pushed to OneDrive.' );
	}

	private function perform_import( ContentMapRecord $record, OneDriveItem $onedrive_meta ): SyncResult {
		try {
			$download = $this->onedrive->download( $onedrive_meta->item_id );
			$result   = $this->docx_converter->docx_to_html( $download->file_bytes );
		} catch ( \Throwable $e ) {
			return new SyncResult( $record->post_id, SyncDecision::IMPORT, false, 'Import failed: ' . $e->getMessage() );
		}

		$this->content_provider->create_revision_from_import( $record->post_id, $result->html );

		$record->mirror_status    = 'synced';
		$record->last_import_hash = $download->item->content_hash;
		// last_export_hash is intentionally left unchanged: the WordPress
		// published version was not touched, only a new revision was
		// created, so what "was last exported" from WP hasn't changed.

		$this->repository->save( $record );
		$this->repository->record_version(
			$record->id,
			'import',
			hash( 'sha256', $download->file_bytes ),
			strlen( $download->file_bytes ),
			$download->item->graph_version_id
		);

		$message = $result->has_warnings()
			? 'OneDrive changes imported as a draft revision, with conversion warnings.'
			: 'OneDrive changes imported as a draft revision.';

		return new SyncResult( $record->post_id, SyncDecision::IMPORT, true, $message, $result->unsupported_elements );
	}

	private function flag_conflict( ContentMapRecord $record ): SyncResult {
		$record->mirror_status = 'conflict';
		$this->repository->save( $record );

		return new SyncResult(
			$record->post_id,
			SyncDecision::CONFLICT,
			true, // the *operation* succeeded: correctly detecting and flagging a conflict is success, not failure
			'Both WordPress and the OneDrive mirror changed since the last sync. Flagged for manual review; neither side was overwritten.'
		);
	}

	private function build_path( ContentMapRecord $record ): string {
		return rtrim( $this->base_folder_path, '/' ) . '/' . $record->post_type . '-' . $record->post_id . '.docx';
	}
}
