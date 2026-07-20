<?php
/**
 * @package MCPSuite\Modules\Seo
 */

declare( strict_types = 1 );

namespace MCPSuite\Modules\Seo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SeoMetadataProviderResolver {

	public function resolve(): ?SeoMetadataProviderInterface {
		$rank_math = new RankMathAdapter();
		if ( $rank_math->is_available() ) {
			return $rank_math;
		}

		$yoast = new YoastAdapter();
		if ( $yoast->is_available() ) {
			return $yoast;
		}

		return null;
	}
}
