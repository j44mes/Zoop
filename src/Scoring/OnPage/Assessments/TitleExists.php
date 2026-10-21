<?php
/**
 * Title exists assessment.
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
 * Awards 7 points when the title tag is non-empty.
 */
final class TitleExists implements Assessment {
	public function id(): string {
		return 'title.exists';
	}

	public function category(): string {
		return 'title';
	}

	public function max(): int {
		return 7;
	}

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult {
		unset( $keyphrase, $normaliser );
		if ( MetadataEvidence::NOT_OBSERVED === $page->titleEvidence ) {
			$withheld = EvidenceGate::metadata( $this, $page->titleEvidence );
			if ( $withheld instanceof AssessmentResult ) {
				return $withheld;
			}
		}

		$exists = '' !== trim( $page->titleTag );

		return AssessmentResult::score(
			$this,
			$exists ? 7 : 0,
			array(
				'exists'   => $exists,
				'evidence' => $page->titleEvidence,
			),
			EvidenceGate::note(
				$exists
					? __( 'A title tag is present.', 'seo-studio' )
					: __( 'The page has no title tag.', 'seo-studio' ),
				$page->titleEvidence
			),
			$exists
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Add a title tag that describes the page.', 'seo-studio' ),
			$page->titleTag
		);
	}
}
