<?php
/**
 * Separate CSS file.
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
 * Awards 3 points when the fetched page links a stylesheet. Otherwise not checked.
 */
final class CodeStylesheet implements Assessment {
	public function id(): string {
		return 'code.stylesheet';
	}

	public function category(): string {
		return 'code';
	}

	public function max(): int {
		return 3;
	}

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult {
		unset( $keyphrase, $normaliser );
		if ( ! $page->codeChecksAvailable() ) {
			return AssessmentResult::score(
				$this,
				0,
				array( 'checked' => false ),
				__( 'A full-page fetch is required before the stylesheet check can run.', 'seo-studio' ),
				__( 'Run Verify live to score the code checks.', 'seo-studio' ),
				'',
				'not_checked'
			);
		}

		$present = true === $page->hasExternalCss;

		return AssessmentResult::score(
			$this,
			$present ? 3 : 0,
			array( 'stylesheet' => $present ),
			$present
				? __( 'The page links a separate CSS file.', 'seo-studio' )
				: __( 'The page does not link a separate CSS file.', 'seo-studio' ),
			$present
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Load styles from a separate CSS file.', 'seo-studio' )
		);
	}
}
