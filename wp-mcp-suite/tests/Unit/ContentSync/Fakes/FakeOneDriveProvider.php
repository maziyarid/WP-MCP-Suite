<?php
/**
 * @package MCPSuite\Tests\Unit\ContentSync\Fakes
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\ContentSync\Fakes;

use MCPSuite\Core\Graph\GraphException;
use MCPSuite\Modules\ContentSync\OneDriveDownload;
use MCPSuite\Modules\ContentSync\OneDriveItem;
use MCPSuite\Modules\ContentSync\OneDriveProviderInterface;

final class FakeOneDriveProvider implements OneDriveProviderInterface {

	/** @var array<string,OneDriveItem> item_id => item */
	private array $items = array();

	/** @var array<string,string> item_id => file bytes */
	private array $contents = array();

	private int $next_id = 1;

	public int $upload_call_count = 0;
	public int $download_call_count = 0;
	public int $get_metadata_call_count = 0;

	public ?GraphException $throw_on_upload = null;
	public ?GraphException $throw_on_get_metadata = null;
	public ?GraphException $throw_on_download = null;

	public function upload( string $path, string $file_bytes ): OneDriveItem {
		$this->upload_call_count++;

		if ( $this->throw_on_upload ) {
			throw $this->throw_on_upload;
		}

		$item_id = 'item-' . $this->next_id++;
		$item    = new OneDriveItem(
			item_id: $item_id,
			drive_id: 'fake-drive',
			content_hash: hash( 'sha256', $file_bytes ),
			graph_version_id: 'v' . $this->next_id,
			size_bytes: strlen( $file_bytes )
		);

		$this->items[ $item_id ]    = $item;
		$this->contents[ $item_id ] = $file_bytes;

		return $item;
	}

	public function download( string $item_id ): OneDriveDownload {
		$this->download_call_count++;

		if ( $this->throw_on_download ) {
			throw $this->throw_on_download;
		}

		if ( ! isset( $this->items[ $item_id ] ) ) {
			throw new GraphException( "Fake item {$item_id} not found.", 404, 'itemNotFound' );
		}

		return new OneDriveDownload( $this->contents[ $item_id ], $this->items[ $item_id ] );
	}

	public function get_metadata( string $item_id ): OneDriveItem {
		$this->get_metadata_call_count++;

		if ( $this->throw_on_get_metadata ) {
			throw $this->throw_on_get_metadata;
		}

		if ( ! isset( $this->items[ $item_id ] ) ) {
			throw new GraphException( "Fake item {$item_id} not found.", 404, 'itemNotFound' );
		}

		return $this->items[ $item_id ];
	}

	/**
	 * Test helper: simulate the OneDrive-side file being edited by
	 * something other than this plugin (a human editing the Word doc),
	 * which changes its hash without going through upload().
	 */
	public function simulate_external_edit( string $item_id, string $new_bytes ): void {
		$existing = $this->items[ $item_id ];

		$this->items[ $item_id ]    = new OneDriveItem(
			item_id: $existing->item_id,
			drive_id: $existing->drive_id,
			content_hash: hash( 'sha256', $new_bytes ),
			graph_version_id: $existing->graph_version_id . '-edited',
			size_bytes: strlen( $new_bytes )
		);
		$this->contents[ $item_id ] = $new_bytes;
	}
}
