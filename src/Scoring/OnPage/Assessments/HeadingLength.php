<?php
/**
 * H1 length assessment.
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
 * Full points for an H1 of 1 to 40 characters.
 */
final class HeadingLength implements Assessment {
	public function id(): string {
		return 'headings.h1_length';
	}

	public function category(): string {
		return 'headings';
	}

	public function max(): int {
		return 2;
	}

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult {
		unset( $keyphrase, $normaliser );
		$h1     = trim( $page->firstH1() );
		$length = mb_strlen( $h1 );
		if ( $length >= 1 && $length <= 40 ) {
			$earned = 2;
		} elseif ( $length >= 41 && $length <= 60 ) {
			$earned = 1;
		} else {
			$earned = 0;
		}

		return AssessmentResult::score(
			$this,
			$earned,
			array( 'length' => $length ),
			sprintf(
				/* translators: %d: H1 length in characters */
				__( 'The H1 is %d characters. The full score is 1 to 40.', 'seo-studio' ),
				$length
			),
			2 === $earned
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Shorten the H1 to 40 characters or fewer.', 'seo-studio' ),
			$h1
		);
	}
}
