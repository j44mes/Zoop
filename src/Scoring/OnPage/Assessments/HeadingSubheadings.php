<?php
/**
 * Keyphrase in a subheading.
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
 * Full points for an entire match in H2–H6. A partial match earns 1.
 */
final class HeadingSubheadings implements Assessment {
	public function id(): string {
		return 'headings.subheadings';
	}

	public function category(): string {
		return 'headings';
	}

	public function max(): int {
		return 2;
	}

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult {
		$entire   = false;
		$partial  = false;
		$evidence = '';
		foreach ( $page->subheadings() as $heading ) {
			$match = $normaliser->matchField( $heading['text'], $keyphrase );
			if ( $match->entire ) {
				$entire   = true;
				$evidence = $heading['text'];
			} elseif ( $match->partial && '' === $evidence ) {
				$partial  = true;
				$evidence = $heading['text'];
			}
		}

		if ( $entire ) {
			$earned = 2;
		} elseif ( $partial ) {
			$earned = 1;
		} else {
			$earned = 0;
		}

		return AssessmentResult::score(
			$this,
			$earned,
			array(
				'entire'  => $entire,
				'partial' => $partial,
			),
			2 === $earned
				? __( 'A subheading contains the full keyphrase.', 'seo-studio' )
				: ( 1 === $earned
					? __( 'A subheading contains only part of the keyphrase.', 'seo-studio' )
					: __( 'No subheading contains the keyphrase.', 'seo-studio' ) ),
			2 === $earned
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Use the keyphrase in at least one subheading.', 'seo-studio' ),
			$evidence
		);
	}
}
