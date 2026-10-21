<?php
/**
 * Title and H1 should not be identical.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Scoring\OnPage\Assessments;

use SEOStudio\Content\MetadataEvidence;
use SEOStudio\Content\PageModel;
use SEOStudio\Scoring\OnPage\Assessment;
use SEOStudio\Scoring\OnPage\AssessmentResult;
use SEOStudio\Scoring\OnPage\EvidenceGate;
use SEOStudio\Scoring\OnPage\Normaliser;

/**
 * Awards 2 points when the normalised title and H1 differ.
 */
final class HeadingTitleDiffers implements Assessment {
	public function id(): string {
		return 'headings.title_differs';
	}

	public function category(): string {
		return 'headings';
	}

	public function max(): int {
		return 2;
	}

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult {
		unset( $keyphrase );
		$withheld = EvidenceGate::documentH1( $this, $page );
		if ( $withheld instanceof AssessmentResult ) {
			return $withheld;
		}
		if ( MetadataEvidence::NOT_OBSERVED === $page->titleEvidence ) {
			$missing = EvidenceGate::metadata( $this, $page->titleEvidence );
			if ( $missing instanceof AssessmentResult ) {
				return $missing;
			}
		}

		$title   = $normaliser->normalise( $page->titleTag );
		$h1      = $normaliser->normalise( $page->firstDocumentH1() );
		$differs = $title !== $h1;

		return AssessmentResult::score(
			$this,
			$differs ? 2 : 0,
			array( 'differs' => $differs ),
			$differs
				? __( 'The title tag and the H1 are different.', 'seo-studio' )
				: __( 'The title tag and the H1 are the same.', 'seo-studio' ),
			$differs
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Make the title tag and the H1 different.', 'seo-studio' ),
			$page->titleTag . ' / ' . $page->firstDocumentH1()
		);
	}
}
