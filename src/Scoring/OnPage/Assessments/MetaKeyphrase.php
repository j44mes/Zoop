<?php
/**
 * Keyphrase in the meta description.
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
 * An entire match earns 5. Coverage of at least 50 percent earns 3.
 */
final class MetaKeyphrase implements Assessment {
	public function id(): string {
		return 'meta.keyphrase';
	}

	public function category(): string {
		return 'meta';
	}

	public function max(): int {
		return 5;
	}

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult {
		$withheld = EvidenceGate::metadata( $this, $page->descriptionEvidence );
		if ( $withheld instanceof AssessmentResult ) {
			return $withheld;
		}

		$description = trim( $page->metaDescription );
		$match       = $normaliser->matchField( $description, $keyphrase );
		if ( '' === $description ) {
			$earned = 0;
		} elseif ( $match->entire ) {
			$earned = 5;
		} elseif ( $match->coverage >= 0.50 ) {
			$earned = 3;
		} else {
			$earned = 0;
		}

		return AssessmentResult::score(
			$this,
			$earned,
			array(
				'entire'   => $match->entire,
				'coverage' => $match->coverage,
			),
			EvidenceGate::note(
				5 === $earned
					? __( 'The meta description contains the full keyphrase.', 'seo-studio' )
					: __( 'The meta description does not contain the full keyphrase.', 'seo-studio' ),
				$page->descriptionEvidence
			),
			5 === $earned
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Mention the full keyphrase in the meta description.', 'seo-studio' ),
			$description
		);
	}
}
