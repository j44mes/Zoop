<?php
/**
 * Partial keyphrase in the title.
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
 * Awards 2 points when at least one keyphrase content word is in the title.
 */
final class TitlePartialKeyphrase implements Assessment {
	public function id(): string {
		return 'title.partial_keyphrase';
	}

	public function category(): string {
		return 'title';
	}

	public function max(): int {
		return 2;
	}

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult {
		$withheld = EvidenceGate::metadata( $this, $page->titleEvidence );
		if ( $withheld instanceof AssessmentResult ) {
			return $withheld;
		}

		$match = $normaliser->matchField( $page->titleTag, $keyphrase );

		return AssessmentResult::score(
			$this,
			$match->partial ? 2 : 0,
			array( 'partial' => $match->partial ),
			EvidenceGate::note(
				$match->partial
					? __( 'Part of the keyphrase is in the title.', 'seo-studio' )
					: __( 'None of the keyphrase words are in the title.', 'seo-studio' ),
				$page->titleEvidence
			),
			$match->partial
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Use at least one word from the keyphrase in the title.', 'seo-studio' ),
			$page->titleTag
		);
	}
}
