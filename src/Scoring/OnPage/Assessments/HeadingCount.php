<?php
/**
 * Number of H1 elements.
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
 * Awards 4 points only when the page has exactly one H1.
 */
final class HeadingCount implements Assessment {
	public function id(): string {
		return 'headings.h1_count';
	}

	public function category(): string {
		return 'headings';
	}

	public function max(): int {
		return 4;
	}

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult {
		unset( $keyphrase, $normaliser );
		$count  = count( $page->h1 );
		$earned = 1 === $count ? 4 : 0;

		return AssessmentResult::score(
			$this,
			$earned,
			array( 'count' => $count ),
			sprintf(
				/* translators: %d: number of H1 elements */
				__( 'The page has %d H1 elements. Exactly one is required.', 'seo-studio' ),
				$count
			),
			4 === $earned
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Use exactly one H1 on the page.', 'seo-studio' ),
			implode( ' | ', $page->h1 )
		);
	}
}
