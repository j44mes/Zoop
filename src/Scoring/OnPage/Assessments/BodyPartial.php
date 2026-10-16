<?php
/**
 * Partial keyphrase coverage in the body.
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
 * Coverage above 70 percent earns 5. 50 to 70 percent earns 3.
 */
final class BodyPartial implements Assessment {
	public function id(): string {
		return 'body.partial';
	}

	public function category(): string {
		return 'body';
	}

	public function max(): int {
		return 5;
	}

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult {
		$coverage = $normaliser->matchField( $page->bodyText, $keyphrase )->coverage;
		if ( $coverage > 0.70 ) {
			$earned = 5;
		} elseif ( $coverage >= 0.50 ) {
			$earned = 3;
		} else {
			$earned = 0;
		}

		return AssessmentResult::score(
			$this,
			$earned,
			array( 'coverage' => $coverage ),
			sprintf(
				/* translators: %d: percentage of keyphrase words found */
				__( '%d%% of the keyphrase words appear in the body.', 'seo-studio' ),
				(int) round( $coverage * 100 )
			),
			5 === $earned
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Use more of the keyphrase words in the body.', 'seo-studio' ),
			''
		);
	}
}
