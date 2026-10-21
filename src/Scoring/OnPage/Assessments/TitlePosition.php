<?php
/**
 * Keyphrase position in the title.
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
 * Earlier keyphrase placement scores higher. Position 1 earns all 6 points.
 */
final class TitlePosition implements Assessment {
	public function id(): string {
		return 'title.position';
	}

	public function category(): string {
		return 'title';
	}

	public function max(): int {
		return 6;
	}

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult {
		$withheld = EvidenceGate::fetchedTitle( $this, $page->titleEvidence );
		if ( $withheld instanceof AssessmentResult ) {
			return $withheld;
		}

		$match    = $normaliser->matchField( $page->titleTag, $keyphrase );
		$position = $match->position;
		if ( null === $position ) {
			$earned = 0;
		} elseif ( 1 === $position ) {
			$earned = 6;
		} elseif ( $position <= (int) ceil( $match->wordCount / 2 ) ) {
			$earned = 4;
		} else {
			$earned = 2;
		}

		return AssessmentResult::score(
			$this,
			$earned,
			array(
				'position'   => $position,
				'word_count' => $match->wordCount,
			),
			EvidenceGate::note(
				null === $position
					? __( 'The full keyphrase is not in the title, so position cannot be scored.', 'seo-studio' )
					: sprintf(
						/* translators: %d: 1-based word position */
						__( 'The keyphrase starts at word %d of the title.', 'seo-studio' ),
						$position
					),
				$page->titleEvidence
			),
			6 === $earned
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Move the keyphrase to the start of the title.', 'seo-studio' ),
			$page->titleTag
		);
	}
}
