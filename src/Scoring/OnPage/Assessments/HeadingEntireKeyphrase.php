<?php
/**
 * Entire keyphrase in the first H1.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Scoring\OnPage\Assessments;

use SEOStudio\Content\PageModel;
use SEOStudio\Scoring\OnPage\Assessment;
use SEOStudio\Scoring\OnPage\AssessmentResult;
use SEOStudio\Scoring\OnPage\EvidenceGate;
use SEOStudio\Scoring\OnPage\Normaliser;

/**
 * Awards 3 points when the first H1 contains the full keyphrase.
 */
final class HeadingEntireKeyphrase implements Assessment {
	public function id(): string {
		return 'headings.entire_keyphrase';
	}

	public function category(): string {
		return 'headings';
	}

	public function max(): int {
		return 3;
	}

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult {
		$withheld = EvidenceGate::documentH1( $this, $page );
		if ( $withheld instanceof AssessmentResult ) {
			return $withheld;
		}

		$h1    = $page->firstDocumentH1();
		$match = $normaliser->matchField( $h1, $keyphrase );

		return AssessmentResult::score(
			$this,
			$match->entire ? 3 : 0,
			array( 'entire' => $match->entire ),
			$match->entire
				? __( 'The full keyphrase is in the H1.', 'seo-studio' )
				: __( 'The full keyphrase is missing from the H1.', 'seo-studio' ),
			$match->entire
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Use the full keyphrase in the H1.', 'seo-studio' ),
			$h1
		);
	}
}
