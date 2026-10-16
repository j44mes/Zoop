<?php
/**
 * Every keyphrase word in the body.
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
 * Awards 2 points when body coverage is 100 percent.
 */
final class BodyEveryWord implements Assessment {
	public function id(): string {
		return 'body.every_word';
	}

	public function category(): string {
		return 'body';
	}

	public function max(): int {
		return 2;
	}

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult {
		$coverage = $normaliser->matchField( $page->bodyText, $keyphrase )->coverage;
		$complete = 1.0 === $coverage && array() !== $normaliser->contentWords( $keyphrase );

		return AssessmentResult::score(
			$this,
			$complete ? 2 : 0,
			array( 'coverage' => $coverage ),
			$complete
				? __( 'Every keyphrase word appears in the body.', 'seo-studio' )
				: __( 'At least one keyphrase word is missing from the body.', 'seo-studio' ),
			$complete
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Include every keyphrase word somewhere in the body.', 'seo-studio' ),
			''
		);
	}
}
