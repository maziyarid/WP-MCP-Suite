<?php
/**
 * @package MCPSuite\Tests\Unit\ContentSync\Fakes
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\ContentSync\Fakes;

use MCPSuite\Modules\ContentSync\ContentMapRecord;
use MCPSuite\Modules\ContentSync\ContentMapRepositoryInterface;

final class InMemoryContentMapRepository implements ContentMapRepositoryInterface {

	/** @var array<int,ContentMapRecord> keyed by post_id */
	private array $by_post_id = array();

	private int $next_id = 1;

	/** @var array{content_map_id:int,direction:string,checksum:string,size:int,onedrive_version_id:?string}[] */
	public array $recorded_versions = array();

	public int $save_call_count = 0;

	public function find_by_post_id( int $post_id ): ?ContentMapRecord {
		return $this->by_post_id[ $post_id ] ?? null;
	}

	public function create( int $post_id, string $post_type ): ContentMapRecord {
		$record                       = new ContentMapRecord( id: $this->next_id++, post_id: $post_id, post_type: $post_type );
		$this->by_post_id[ $post_id ] = $record;
		return $record;
	}

	public function opt_in( int $post_id, string $post_type ): ContentMapRecord {
		$existing = $this->find_by_post_id( $post_id );

		if ( $existing ) {
			$existing->phi_excluded = false;
			return $existing;
		}

		$record                       = new ContentMapRecord( id: $this->next_id++, post_id: $post_id, post_type: $post_type, phi_excluded: false );
		$this->by_post_id[ $post_id ] = $record;
		return $record;
	}

	public function save( ContentMapRecord $record ): void {
		$this->save_call_count++;
		$this->by_post_id[ $record->post_id ] = $record;
	}

	public function find_conflicts(): array {
		return array_values( array_filter( $this->by_post_id, static fn( ContentMapRecord $r ) => 'conflict' === $r->mirror_status ) );
	}

	public function record_version(
		int $content_map_id,
		string $direction,
		string $file_checksum,
		int $file_size,
		?string $onedrive_version_id
	): void {
		$this->recorded_versions[] = array(
			'content_map_id'      => $content_map_id,
			'direction'           => $direction,
			'checksum'            => $file_checksum,
			'size'                => $file_size,
			'onedrive_version_id' => $onedrive_version_id,
		);
	}

	/**
	 * Test helper: seed a pre-existing record directly (bypassing create()),
	 * to set up "post already has a mirror with these hashes" scenarios.
	 */
	public function seed( ContentMapRecord $record ): void {
		$this->by_post_id[ $record->post_id ] = $record;
	}
}
