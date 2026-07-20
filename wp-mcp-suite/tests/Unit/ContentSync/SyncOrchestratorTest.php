<?php
/**
 * @package MCPSuite\Tests\Unit\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\ContentSync;

use MCPSuite\Core\Graph\GraphException;
use MCPSuite\Modules\ContentSync\ContentMapRecord;
use MCPSuite\Modules\ContentSync\SyncDecision;
use MCPSuite\Modules\ContentSync\SyncEngine;
use MCPSuite\Modules\ContentSync\SyncOrchestrator;
use MCPSuite\Tests\Unit\ContentSync\Fakes\FakeContentProvider;
use MCPSuite\Tests\Unit\ContentSync\Fakes\FakeDocxConverter;
use MCPSuite\Tests\Unit\ContentSync\Fakes\FakeOneDriveProvider;
use MCPSuite\Tests\Unit\ContentSync\Fakes\InMemoryContentMapRepository;
use PHPUnit\Framework\TestCase;

final class SyncOrchestratorTest extends TestCase {

	private FakeContentProvider $content;
	private FakeOneDriveProvider $onedrive;
	private FakeDocxConverter $docx;
	private InMemoryContentMapRepository $repository;
	private SyncOrchestrator $orchestrator;

	protected function setUp(): void {
		parent::setUp();

		$this->content     = new FakeContentProvider();
		$this->onedrive    = new FakeOneDriveProvider();
		$this->docx        = new FakeDocxConverter();
		$this->repository  = new InMemoryContentMapRepository();
		$this->orchestrator = new SyncOrchestrator(
			$this->content,
			$this->onedrive,
			$this->docx,
			$this->repository,
			new SyncEngine(),
			'/MCP Suite Content Mirror'
		);
	}

	public function test_brand_new_post_not_opted_in_is_skipped_without_touching_onedrive(): void {
		$this->content->seed_post( 42, 'post', 'Rhinoplasty Guide', '<p>hello</p>' );

		$result = $this->orchestrator->sync_post( 42 );

		$this->assertTrue( $result->success );
		$this->assertSame( SyncDecision::SKIP, $result->decision );
		$this->assertSame( 0, $this->onedrive->upload_call_count );
		$this->assertStringContainsString( 'not yet opted into', $result->message );
	}

	public function test_opted_in_post_performs_initial_export(): void {
		$this->content->seed_post( 42, 'post', 'Rhinoplasty Guide', '<p>hello</p>' );
		$this->repository->opt_in( 42, 'post' );

		$result = $this->orchestrator->sync_post( 42 );

		$this->assertTrue( $result->success );
		$this->assertSame( SyncDecision::EXPORT, $result->decision );
		$this->assertSame( 1, $this->onedrive->upload_call_count );
		$this->assertSame( 1, $this->docx->export_call_count );

		$record = $this->repository->find_by_post_id( 42 );
		$this->assertSame( 'synced', $record->mirror_status );
		$this->assertNotNull( $record->onedrive_item_id );
		$this->assertSame( 1, count( $this->repository->recorded_versions ) );
		$this->assertSame( 'export', $this->repository->recorded_versions[0]['direction'] );
	}

	public function test_second_tick_with_no_changes_skips_without_any_docx_conversion(): void {
		$this->content->seed_post( 42, 'post', 'Rhinoplasty Guide', '<p>hello</p>' );
		$this->repository->opt_in( 42, 'post' );
		$this->orchestrator->sync_post( 42 ); // initial export

		$result = $this->orchestrator->sync_post( 42 ); // second tick, nothing changed

		$this->assertSame( SyncDecision::SKIP, $result->decision );
		$this->assertSame( 1, $this->docx->export_call_count, 'no second conversion should happen when nothing changed' );
	}

	public function test_wp_content_change_triggers_export_on_next_tick(): void {
		$this->content->seed_post( 42, 'post', 'Rhinoplasty Guide', '<p>hello</p>' );
		$this->repository->opt_in( 42, 'post' );
		$this->orchestrator->sync_post( 42 );

		$this->content->seed_post( 42, 'post', 'Rhinoplasty Guide', '<p>hello, updated</p>' );

		$result = $this->orchestrator->sync_post( 42 );

		$this->assertSame( SyncDecision::EXPORT, $result->decision );
		$this->assertSame( 2, $this->docx->export_call_count );
	}

	public function test_onedrive_only_change_triggers_import_as_a_draft_revision_not_a_direct_overwrite(): void {
		$this->content->seed_post( 42, 'post', 'Rhinoplasty Guide', '<p>hello</p>' );
		$this->repository->opt_in( 42, 'post' );
		$this->orchestrator->sync_post( 42 );

		$record = $this->repository->find_by_post_id( 42 );
		$this->onedrive->simulate_external_edit( $record->onedrive_item_id, 'edited by a human in Word' );

		$result = $this->orchestrator->sync_post( 42 );

		$this->assertSame( SyncDecision::IMPORT, $result->decision );
		$this->assertTrue( $result->success );
		$this->assertCount( 1, $this->content->imported_revisions[42] ?? array(), 'must land as a revision, never overwrite the live post directly' );
	}

	public function test_both_sides_changed_is_flagged_conflict_and_neither_side_is_overwritten(): void {
		$this->content->seed_post( 42, 'post', 'Rhinoplasty Guide', '<p>hello</p>' );
		$this->repository->opt_in( 42, 'post' );
		$this->orchestrator->sync_post( 42 );

		$record = $this->repository->find_by_post_id( 42 );
		$this->onedrive->simulate_external_edit( $record->onedrive_item_id, 'edited in Word' );
		$this->content->seed_post( 42, 'post', 'Rhinoplasty Guide', '<p>edited in WordPress</p>' );

		$uploads_before_conflict_tick = $this->onedrive->upload_call_count;

		$result = $this->orchestrator->sync_post( 42 );

		$this->assertSame( SyncDecision::CONFLICT, $result->decision );
		$this->assertTrue( $result->success, 'correctly detecting a conflict is a successful operation' );
		$this->assertSame( $uploads_before_conflict_tick, $this->onedrive->upload_call_count, 'must not push WP over the OneDrive edit' );
		$this->assertArrayNotHasKey( 42, $this->content->imported_revisions, 'must not pull OneDrive over the WP edit' );

		$stored = $this->repository->find_by_post_id( 42 );
		$this->assertSame( 'conflict', $stored->mirror_status );
	}

	public function test_conflict_is_not_re_resolved_automatically_on_a_later_tick_with_no_further_changes(): void {
		$this->content->seed_post( 42, 'post', 'Rhinoplasty Guide', '<p>hello</p>' );
		$this->repository->opt_in( 42, 'post' );
		$this->orchestrator->sync_post( 42 );

		$record = $this->repository->find_by_post_id( 42 );
		$this->onedrive->simulate_external_edit( $record->onedrive_item_id, 'edited in Word' );
		$this->content->seed_post( 42, 'post', 'Rhinoplasty Guide', '<p>edited in WordPress</p>' );
		$this->orchestrator->sync_post( 42 ); // -> conflict

		// A human has not resolved anything yet; hashes on record are still
		// the pre-conflict baseline, so a naive re-run must keep conflicting,
		// not silently pick a side just because nothing changed *since* the conflict was flagged.
		$result = $this->orchestrator->sync_post( 42 );

		$this->assertSame( SyncDecision::CONFLICT, $result->decision );
	}

	public function test_onedrive_upload_failure_during_initial_export_does_not_mark_synced(): void {
		$this->content->seed_post( 42, 'post', 'Rhinoplasty Guide', '<p>hello</p>' );
		$this->repository->opt_in( 42, 'post' );
		$this->onedrive->throw_on_upload = new GraphException( 'simulated outage', 503, 'serviceUnavailable' );

		$result = $this->orchestrator->sync_post( 42 );

		$this->assertFalse( $result->success );
		$this->assertStringContainsString( 'simulated outage', $result->message );

		$record = $this->repository->find_by_post_id( 42 );
		$this->assertSame( 'pending', $record->mirror_status, 'a failed export must not be recorded as synced' );
		$this->assertNull( $record->onedrive_item_id );
	}

	public function test_missing_post_is_reported_as_a_failed_skip_not_an_uncaught_exception(): void {
		// No seed_post() call — post 999 does not exist.
		$result = $this->orchestrator->sync_post( 999 );

		$this->assertSame( SyncDecision::SKIP, $result->decision );
		$this->assertFalse( $result->success );
	}

	public function test_import_conversion_warnings_are_surfaced_on_the_result(): void {
		$this->content->seed_post( 42, 'post', 'Rhinoplasty Guide', '<p>hello</p>' );
		$this->repository->opt_in( 42, 'post' );
		$this->orchestrator->sync_post( 42 );

		$record = $this->repository->find_by_post_id( 42 );
		$this->onedrive->simulate_external_edit( $record->onedrive_item_id, 'content with an embedded image' );

		// FakeDocxConverter never produces warnings by default; this test
		// documents the contract (warnings flow through to SyncResult) using
		// a purpose-built inline converter rather than changing the shared fake.
		$warningConverter = new class implements \MCPSuite\Modules\ContentSync\DocxConverterInterface {
			public function html_to_docx( string $html, string $title ): string {
				return 'unused';
			}
			public function docx_to_html( string $docx_bytes ): \MCPSuite\Modules\ContentSync\DocxImportResult {
				return new \MCPSuite\Modules\ContentSync\DocxImportResult( '<p>ok</p>', array( 'An embedded image was found and was not imported automatically.' ) );
			}
		};

		$orchestrator = new SyncOrchestrator( $this->content, $this->onedrive, $warningConverter, $this->repository, new SyncEngine(), '/base' );
		$result       = $orchestrator->sync_post( 42 );

		$this->assertSame( SyncDecision::IMPORT, $result->decision );
		$this->assertNotEmpty( $result->warnings );
	}
}
