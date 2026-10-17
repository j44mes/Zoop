<?php
/**
 * Keyphrase position in the H1.
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
 * Position 1 earns 5. Position 4 or later earns nothing.
 */
final class HeadingPosition implements Assessment {
	public function id(): string {
		return 'headings.position';
	}

	public function category(): string {
		return 'headings';
	}

	public function max(): int {
		return 5;
	}

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult {
		$match    = $normaliser->matchField( $page->firstH1(), $keyphrase );
		$position = $match->position;
		$earned   = match ( $position ) {
			1       => 5,
			2       => 4,
			3       => 2,
			default => 0,
		};

		return AssessmentResult::score(
			$this,
			$earned,
			array( 'position' => $position ),
			null === $position
				? __( 'The full keyphrase is not in the H1, so position cannot be scored.', 'seo-studio' )
				: sprintf(
					/* translators: %d: 1-based word position */
					__( 'The keyphrase starts at word %d of the H1.', 'seo-studio' ),
					$position
				),
			5 === $earned
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Move the keyphrase to the start of the H1.', 'seo-studio' ),
			$page->firstH1()
		);
	}
}
