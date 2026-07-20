<?php
/**
 * Real Microsoft Graph implementation of OneDriveProviderInterface, using
 * the drive-relative-path upload endpoint (PUT .../root:/{path}:/content)
 * so callers work in terms of a human-readable path rather than needing to
 * resolve parent folder item IDs themselves.
 *
 * @package MCPSuite\Modules\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\ContentSync;

use MCPSuite\Core\Graph\GraphAuthService;
use MCPSuite\Core\Graph\GraphException;
use MCPSuite\Core\Graph\GraphHttpClientInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GraphOneDriveProvider implements OneDriveProviderInterface {

	private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

	public function __construct(
		private readonly GraphHttpClientInterface $http_client,
		private readonly GraphAuthService $auth,
		private readonly string $drive_id
	) {}

	public function upload( string $path, string $file_bytes ): OneDriveItem {
		// Graph's simple upload endpoint accepts files up to 4MB, which
		// comfortably covers a single post's DOCX export. Files larger
		// than that (unlikely for this use case) would need the resumable
		// upload session endpoint instead — not implemented here; a
		// >4MB export fails loudly with a GraphException rather than
		// silently truncating.
		if ( strlen( $file_bytes ) > 4 * 1024 * 1024 ) {
			throw new GraphException( 'File exceeds the 4MB simple-upload limit; resumable upload is not yet implemented.', null, null );
		}

		$url = sprintf(
			'%s/drives/%s/root:/%s:/content',
			self::GRAPH_BASE,
			rawurlencode( $this->drive_id ),
			$this->encode_path( $path )
		);

		$response = $this->http_client->request(
			'PUT',
			$url,
			array(
				'Authorization' => 'Bearer ' . $this->auth->get_access_token(),
				'Content-Type'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			),
			$file_bytes
		);

		return $this->item_from_response( $response, 'upload' );
	}

	public function download( string $item_id ): OneDriveDownload {
		$metadata = $this->get_metadata( $item_id );

		$url = sprintf( '%s/drives/%s/items/%s/content', self::GRAPH_BASE, rawurlencode( $this->drive_id ), rawurlencode( $item_id ) );

		$response = $this->http_client->request(
			'GET',
			$url,
			array( 'Authorization' => 'Bearer ' . $this->auth->get_access_token() )
		);

		if ( ! $response->is_success() ) {
			throw new GraphException( 'Failed to download OneDrive item ' . $item_id, $response->status, $response->graph_error_code() );
		}

		return new OneDriveDownload( $response->body, $metadata );
	}

	public function get_metadata( string $item_id ): OneDriveItem {
		$url = sprintf( '%s/drives/%s/items/%s', self::GRAPH_BASE, rawurlencode( $this->drive_id ), rawurlencode( $item_id ) );

		$response = $this->http_client->request(
			'GET',
			$url,
			array( 'Authorization' => 'Bearer ' . $this->auth->get_access_token() )
		);

		return $this->item_from_response( $response, 'get_metadata' );
	}

	private function item_from_response( \MCPSuite\Core\Graph\GraphHttpResponse $response, string $operation ): OneDriveItem {
		if ( ! $response->is_success() ) {
			throw new GraphException( "OneDrive {$operation} failed.", $response->status, $response->graph_error_code() );
		}

		$json = $response->json();

		if ( empty( $json['id'] ) ) {
			throw new GraphException( "OneDrive {$operation} response was missing an item id.", $response->status, null );
		}

		// quickXorHash is Microsoft's own fast hash and is always present
		// for OneDrive for Business files; sha1Hash is a fallback for
		// older/consumer drive types. Either is fine as an opaque
		// change-detection token — this code never needs to recompute or
		// verify the hash itself, only compare it to a previously stored value.
		$hash = $json['file']['hashes']['quickXorHash']
			?? $json['file']['hashes']['sha1Hash']
			?? (string) ( $json['eTag'] ?? $json['cTag'] ?? '' );

		if ( '' === $hash ) {
			throw new GraphException( "OneDrive {$operation} response contained no usable hash/eTag for change detection.", $response->status, null );
		}

		return new OneDriveItem(
			item_id: (string) $json['id'],
			drive_id: $this->drive_id,
			content_hash: $hash,
			graph_version_id: (string) ( $json['cTag'] ?? $json['eTag'] ?? '' ),
			size_bytes: (int) ( $json['size'] ?? 0 )
		);
	}

	/**
	 * Graph's drive-relative-path syntax requires each path segment to be
	 * percent-encoded but the '/' separators preserved.
	 */
	private function encode_path( string $path ): string {
		$segments = array_filter( explode( '/', $path ), static fn( string $s ) => '' !== $s );
		return implode( '/', array_map( 'rawurlencode', $segments ) );
	}
}
