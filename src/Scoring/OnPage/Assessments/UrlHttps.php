<?php
/**
 * HTTPS assessment.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Scoring\OnPage\Assessments;

use SEOStudio\Content\PageModel;
use SEOStudio\Scoring\OnPage\Assessment;
use SEOStudio\Scoring\OnPage\AssessmentResult;
use SEOStudio\Scoring\OnPage\Normaliser;

/**
 * Awards 5 points when the page URL uses HTTPS.
 */
final class UrlHttps implements Assessment {
	public function id(): string {
		return 'url.https';
	}

	public function category(): string {
		return 'url';
	}

	public function max(): int {
		return 5;
	}

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult {
		unset( $keyphrase, $normaliser );

		return AssessmentResult::score(
			$this,
			$page->isHttps ? 5 : 0,
			array( 'https' => $page->isHttps ),
			$page->isHttps
				? __( 'The page is served over HTTPS.', 'seo-studio' )
				: __( 'The page is not served over HTTPS.', 'seo-studio' ),
			$page->isHttps
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Serve the page over HTTPS.', 'seo-studio' ),
			$page->url
		);
	}
}
