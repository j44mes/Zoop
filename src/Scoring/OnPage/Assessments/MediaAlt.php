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
		$scored   = 0;
		foreach ( $page->images as $image ) {
			$kind = (string) ( $image['kind'] ?? 'html' );
			if ( ! in_array( $kind, array( 'html', 'template' ), true ) ) {
				continue;
			}
			++$scored;
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
				'images'  => $scored,
				'entire'  => $entire,
				'partial' => $partial,
			),
			4 === $earned
				? __( 'An image description contains the full target phrase.', 'seo-studio' )
				: __( 'No image description contains the full target phrase.', 'seo-studio' ),
			4 === $earned
				? __( 'Check that the description still says what the image shows.', 'seo-studio' )
				: __( 'Describe what an informative image shows. Leave the description empty when the image is decorative. Repeating the target phrase is optional.', 'seo-studio' ),
			$evidence
		);
	}
}
