<?php
/**
 * Entire keyphrase mentions in the body.
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
 * Three or more adjacent mentions earn 7 points.
 */
final class BodyMentions implements Assessment {
	public function id(): string {
		return 'body.mentions';
	}

	public function category(): string {
		return 'body';
	}

	public function max(): int {
		return 7;
	}

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult {
		$count = $normaliser->matchField( $page->bodyText, $keyphrase )->entireCount;
		if ( $count >= 3 ) {
			$earned = 7;
		} elseif ( 2 === $count ) {
			$earned = 5;
		} elseif ( 1 === $count ) {
			$earned = 3;
		} else {
			$earned = 0;
		}

		return AssessmentResult::score(
			$this,
			$earned,
			array( 'mentions' => $count ),
			sprintf(
				/* translators: %d: number of keyphrase mentions */
				__( 'The body mentions the full keyphrase %d times.', 'seo-studio' ),
				$count
			),
			7 === $earned
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Mention the full keyphrase in the body where it reads naturally.', 'seo-studio' ),
			''
		);
	}
}
