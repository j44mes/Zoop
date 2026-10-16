<?php
/**
 * Title length assessment.
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
 * Scores the raw title length. 30 to 60 characters is the full 6 points.
 */
final class TitleLength implements Assessment {
	public function id(): string {
		return 'title.length';
	}

	public function category(): string {
		return 'title';
	}

	public function max(): int {
		return 6;
	}

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult {
		unset( $keyphrase, $normaliser );
		$title  = trim( $page->titleTag );
		$length = mb_strlen( $title );
		if ( '' === $title ) {
			$earned = 0;
		} elseif ( $length >= 30 && $length <= 60 ) {
			$earned = 6;
		} elseif ( ( $length >= 20 && $length <= 29 ) || ( $length >= 61 && $length <= 70 ) ) {
			$earned = 4;
		} else {
			$earned = 2;
		}

		return AssessmentResult::score(
			$this,
			$earned,
			array( 'length' => $length ),
			sprintf(
				/* translators: %d: title length in characters */
				__( 'The title is %d characters. The full score is 30 to 60.', 'seo-studio' ),
				$length
			),
			6 === $earned
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Rewrite the title so it is between 30 and 60 characters.', 'seo-studio' ),
			$title
		);
	}
}
