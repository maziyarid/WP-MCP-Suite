<?php
/**
 * @package MCPSuite\Modules\AiWriting
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\AiWriting;

if ( ! defined( 'ABSPATH' ) && ! defined( 'MCP_SUITE_TESTING' ) ) {
	exit;
}

final class AiRun {

	public function __construct(
		public readonly int $id,
		public readonly string $job_id,
		public readonly ?int $post_id,
		public readonly string $provider,
		public readonly string $model,
		public readonly AiRunStatus $status,
		public readonly bool $human_reviewed,
		public readonly ?int $reviewer_id,
		public readonly bool $contains_phi_flag
	) {}
}
