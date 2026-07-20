<?php
/**
 * @package MCPSuite\Tests\Unit\ContentSync\Fakes
 */

declare( strict_types = 1 );

namespace MCPSuite\Tests\Unit\ContentSync\Fakes;

use MCPSuite\Modules\ContentSync\ContentProviderInterface;
use MCPSuite\Modules\ContentSync\PostContent;

final class FakeContentProvider implements ContentProviderInterface {

	/** @var array<int,PostContent> */
	private array $posts = array();

	/** @var array<int,string[]> post_id => imported html strings, in order */
	public array $imported_revisions = array();

	private int $next_revision_id = 500;

	public function seed_post( int $post_id, string $post_type, string $title, string $html ): void {
		$this->posts[ $post_id ] = new PostContent(
			post_id: $post_id,
			post_type: $post_type,
			title: $title,
			rendered_html: $html,
			content_hash: hash( 'sha256', $html )
		);
	}

	public function get_content( int $post_id ): PostContent {
		if ( ! isset( $this->posts[ $post_id ] ) ) {
			throw new \RuntimeException( "Fake post {$post_id} does not exist." );
		}

		return $this->posts[ $post_id ];
	}

	public function create_revision_from_import( int $post_id, string $html ): int {
		$this->imported_revisions[ $post_id ][] = $html;
		return $this->next_revision_id++;
	}
}
