<?php
/**
 * Keyphrase in image alt text.
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
 * An entire alt match earns 4. A partial match earns 2.
 */
final class MediaAlt implements Assessment {
	public function id(): string {
		return 'media.alt';
	}

	public function category(): string {
		return 'media';
	}

	public function max(): int {
		return 4;
	}

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult {
		$entire   = false;
		$partial  = false;
		$evidence = '';
		foreach ( $page->images as $image ) {
			$alt = trim( $image['alt'] );
			if ( '' === $alt ) {
				continue;
			}
			$match = $normaliser->matchField( $alt, $keyphrase );
			if ( $match->entire ) {
				$entire   = true;
				$evidence = $alt;
				break;
			}
			if ( $match->partial && '' === $evidence ) {
				$partial  = true;
				$evidence = $alt;
			}
		}

		if ( $entire ) {
			$earned = 4;
		} elseif ( $partial ) {
			$earned = 2;
		} else {
			$earned = 0;
		}

		return AssessmentResult::score(
			$this,
			$earned,
			array(
				'images'  => count( $page->images ),
				'entire'  => $entire,
				'partial' => $partial,
			),
			4 === $earned
				? __( 'An image alt contains the full keyphrase.', 'seo-studio' )
				: __( 'No image alt contains the full keyphrase.', 'seo-studio' ),
			4 === $earned
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Describe an image with alt text that includes the keyphrase.', 'seo-studio' ),
			$evidence
		);
	}
}
