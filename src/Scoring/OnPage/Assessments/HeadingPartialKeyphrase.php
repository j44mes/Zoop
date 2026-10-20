<?php
/**
 * Partial keyphrase in the first H1.
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
 * Awards 2 points when the first H1 contains any keyphrase content word.
 */
final class HeadingPartialKeyphrase implements Assessment {
	public function id(): string {
		return 'headings.partial_keyphrase';
	}

	public function category(): string {
		return 'headings';
	}

	public function max(): int {
		return 2;
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
			$match->partial ? 2 : 0,
			array( 'partial' => $match->partial ),
			$match->partial
				? __( 'Part of the keyphrase is in the H1.', 'seo-studio' )
				: __( 'None of the keyphrase words are in the H1.', 'seo-studio' ),
			$match->partial
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Use at least one keyphrase word in the H1.', 'seo-studio' ),
			$h1
		);
	}
}
