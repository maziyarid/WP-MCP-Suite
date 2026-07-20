<?php
/**
 * Uploads a large file to OneDrive via Microsoft Graph's resumable upload
 * session (createUploadSession + chunked PUT with Content-Range), always
 * — regardless of file size — rather than branching between simple and
 * resumable upload. Backup archives are essentially always larger than
 * the 4MB simple-upload limit Content Sync's GraphOneDriveProvider uses
 * for single DOCX files, so this class exists separately rather than
 * extending that one, keeping the already-tested Phase 2 upload path
 * untouched.
 *
 * @package MCPSuite\Modules\Backup
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Backup;

use MCPSuite\Core\Graph\GraphAuthService;
use MCPSuite\Core\Graph\GraphException;
use MCPSuite\Core\Graph\GraphHttpClientInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GraphBackupUploader {

	private const GRAPH_BASE  = 'https://graph.microsoft.com/v1.0';
	private const CHUNK_SIZE  = UploadChunkPlanner::GRAPH_CHUNK_ALIGNMENT * 10; // ~3.125 MiB per chunk

	public function __construct(
		private readonly GraphHttpClientInterface $http_client,
		private readonly GraphAuthService $auth,
		private readonly string $drive_id,
		private readonly UploadChunkPlanner $chunk_planner = new UploadChunkPlanner()
	) {}

	/**
	 * @return array{item_id:string,content_hash:string} Item ID and change-detection hash of the uploaded file.
	 * @throws GraphException
	 */
	public function upload( string $path, string $file_bytes ): array {
		$upload_url = $this->create_upload_session( $path );
		$total_size = strlen( $file_bytes );
		$chunks     = $this->chunk_planner->plan( $total_size, self::CHUNK_SIZE );

		if ( array() === $chunks ) {
			throw new GraphException( 'Refusing to upload an empty backup file — this would indicate the database dump silently produced no data.', null, null );
		}

		$final_item = null;

		foreach ( $chunks as $chunk ) {
			$chunk_body = substr( $file_bytes, $chunk->start, $chunk->length );

			$response = $this->http_client->request(
				'PUT',
				$upload_url,
				array(
					'Content-Length' => (string) $chunk->length,
					'Content-Range'  => $chunk->content_range_header(),
				),
				$chunk_body
			);

			if ( $chunk->is_last() ) {
				if ( ! $response->is_success() ) {
					throw new GraphException( 'Final upload chunk was rejected.', $response->status, $response->graph_error_code() );
				}
				$final_item = $response->json();
			} elseif ( 202 !== $response->status ) {
				throw new GraphException( 'Upload chunk was rejected (expected 202 Accepted).', $response->status, $response->graph_error_code() );
			}
		}

		if ( null === $final_item || empty( $final_item['id'] ) ) {
			throw new GraphException( 'Upload session completed without returning a final item.', null, null );
		}

		$hash = $final_item['file']['hashes']['quickXorHash']
			?? $final_item['file']['hashes']['sha1Hash']
			?? (string) ( $final_item['eTag'] ?? '' );

		return array( 'item_id' => (string) $final_item['id'], 'content_hash' => $hash );
	}

	/**
	 * @throws GraphException
	 */
	public function download( string $path ): string {
		$url = sprintf(
			'%s/drives/%s/root:/%s:/content',
			self::GRAPH_BASE,
			rawurlencode( $this->drive_id ),
			$this->encode_path( $path )
		);

		$response = $this->http_client->request(
			'GET',
			$url,
			array( 'Authorization' => 'Bearer ' . $this->auth->get_access_token() )
		);

		if ( ! $response->is_success() ) {
			throw new GraphException( 'Failed to download the backup for verification.', $response->status, $response->graph_error_code() );
		}

		return $response->body;
	}

	/**
	 * @throws GraphException
	 */
	public function delete( string $path ): void {
		$url = sprintf(
			'%s/drives/%s/root:/%s',
			self::GRAPH_BASE,
			rawurlencode( $this->drive_id ),
			$this->encode_path( $path )
		);

		$response = $this->http_client->request(
			'DELETE',
			$url,
			array( 'Authorization' => 'Bearer ' . $this->auth->get_access_token() )
		);

		// 404 is treated as success — the goal state ("this file is gone")
		// is already achieved, whether this call or a previous one did it.
		if ( ! $response->is_success() && 404 !== $response->status ) {
			throw new GraphException( 'Failed to delete a pruned backup file from OneDrive.', $response->status, $response->graph_error_code() );
		}
	}

	private function create_upload_session( string $path ): string {
		$url = sprintf(
			'%s/drives/%s/root:/%s:/createUploadSession',
			self::GRAPH_BASE,
			rawurlencode( $this->drive_id ),
			$this->encode_path( $path )
		);

		$response = $this->http_client->request(
			'POST',
			$url,
			array(
				'Authorization' => 'Bearer ' . $this->auth->get_access_token(),
				'Content-Type'  => 'application/json',
			),
			wp_json_encode( array( 'item' => array( '@microsoft.graph.conflictBehavior' => 'replace' ) ) )
		);

		if ( ! $response->is_success() ) {
			throw new GraphException( 'Failed to create an upload session for the backup.', $response->status, $response->graph_error_code() );
		}

		$json = $response->json();

		if ( empty( $json['uploadUrl'] ) ) {
			throw new GraphException( 'Upload session response was missing uploadUrl.', $response->status, null );
		}

		return (string) $json['uploadUrl'];
	}

	private function encode_path( string $path ): string {
		$segments = array_filter( explode( '/', $path ), static fn( string $s ) => '' !== $s );
		return implode( '/', array_map( 'rawurlencode', $segments ) );
	}
}
