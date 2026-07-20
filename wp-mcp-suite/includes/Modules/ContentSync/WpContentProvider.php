<?php
/**
 * Real WordPress-backed content provider. Deliberately hashes the
 * *rendered* content (post content run through the_content filters) rather
 * than the raw post_content column, so the change-detection hash reflects
 * what the DOCX export will actually contain — a raw-content hash would
 * miss changes caused by, e.g., a block pattern or shortcode whose output
 * changed without the stored content changing.
 *
 * @package MCPSuite\Modules\ContentSync
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\ContentSync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WpContentProvider implements ContentProviderInterface {

	public function get_content( int $post_id ): PostContent {
		$post = get_post( $post_id );

		if ( ! $post ) {
			throw new \RuntimeException( "Post {$post_id} does not exist." );
		}

		$rendered = apply_filters( 'the_content', $post->post_content );

		return new PostContent(
			post_id: $post_id,
			post_type: $post->post_type,
			title: get_the_title( $post ),
			rendered_html: $rendered,
			content_hash: hash( 'sha256', $rendered )
		);
	}

	public function create_revision_from_import( int $post_id, string $html ): int {
		$post = get_post( $post_id );

		if ( ! $post ) {
			throw new \RuntimeException( "Post {$post_id} does not exist." );
		}

		// wp_save_post_revision() only fires from the post-save flow, so an
		// out-of-band import creates the revision directly via
		// _wp_put_post_revision(), which is the same function core uses
		// internally. The published post itself is left untouched — a
		// human reviews and applies the revision from the standard
		// WordPress revisions UI.
		$revision_id = _wp_put_post_revision(
			array(
				'ID'           => $post_id,
				'post_content' => wp_kses_post( $html ),
				'post_title'   => $post->post_title,
			)
		);

		if ( is_wp_error( $revision_id ) || ! is_int( $revision_id ) ) {
			throw new \RuntimeException( "Failed to create a revision for post {$post_id} from imported content." );
		}

		return $revision_id;
	}
}
