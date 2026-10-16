<?php
/**
 * Keyphrase in the URL path.
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
 * The site root scores 5. Otherwise all content words score 5 and more than half scores 3.
 */
final class UrlKeyphrase implements Assessment {
	public function id(): string {
		return 'url.keyphrase';
	}

	public function category(): string {
		return 'url';
	}

	public function max(): int {
		return 5;
	}

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult {
		$path = $page->path();
		if ( '/' === $path ) {
			return AssessmentResult::score(
				$this,
				5,
				array(
					'path' => $path,
					'root' => true,
				),
				__( 'The URL is the site root, which scores as a keyword path.', 'seo-studio' ),
				__( 'No change needed.', 'seo-studio' ),
				$path
			);
		}

		$match = $normaliser->matchField( $path, $keyphrase );
		if ( 1.0 === $match->coverage && array() !== $match->contentWords ) {
			$earned = 5;
		} elseif ( $match->coverage > 0.5 ) {
			$earned = 3;
		} else {
			$earned = 0;
		}

		return AssessmentResult::score(
			$this,
			$earned,
			array(
				'path'     => $path,
				'coverage' => $match->coverage,
			),
			5 === $earned
				? __( 'The URL path contains the keyphrase.', 'seo-studio' )
				: __( 'The URL path does not fully contain the keyphrase.', 'seo-studio' ),
			5 === $earned
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Include the keyphrase in the URL path.', 'seo-studio' ),
			$path
		);
	}
}
