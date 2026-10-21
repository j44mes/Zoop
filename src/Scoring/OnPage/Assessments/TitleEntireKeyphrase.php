<?php
/**
 * Entire keyphrase in the title.
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
 * Awards 6 points when the keyphrase content words are adjacent in the title.
 */
final class TitleEntireKeyphrase implements Assessment {
	public function id(): string {
		return 'title.entire_keyphrase';
	}

	public function category(): string {
		return 'title';
	}

	public function max(): int {
		return 6;
	}

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult {
		$withheld = EvidenceGate::metadata( $this, $page->titleEvidence );
		if ( $withheld instanceof AssessmentResult ) {
			return $withheld;
		}

		$match = $normaliser->matchField( $page->titleTag, $keyphrase );

		return AssessmentResult::score(
			$this,
			$match->entire ? 6 : 0,
			array( 'entire' => $match->entire ),
			EvidenceGate::note(
				$match->entire
					? __( 'The full keyphrase is in the title.', 'seo-studio' )
					: __( 'The full keyphrase is missing from the title.', 'seo-studio' ),
				$page->titleEvidence
			),
			$match->entire
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Include the full keyphrase in the title.', 'seo-studio' ),
			$page->titleTag
		);
	}
}
