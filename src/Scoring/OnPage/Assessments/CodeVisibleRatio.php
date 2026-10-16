<?php
/**
 * Visible text ratio.
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
 * At least 2 percent visible text earns 3. 1 to 2 percent earns 1.
 */
final class CodeVisibleRatio implements Assessment {
	public function id(): string {
		return 'code.visible_ratio';
	}

	public function category(): string {
		return 'code';
	}

	public function max(): int {
		return 3;
	}

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult {
		unset( $keyphrase, $normaliser );
		if ( ! $page->codeChecksAvailable() || null === $page->htmlBytes || null === $page->visibleTextBytes ) {
			return AssessmentResult::score(
				$this,
				0,
				array( 'checked' => false ),
				__( 'A full-page fetch is required before the visible-text check can run.', 'seo-studio' ),
				__( 'Run Verify live to score the code checks.', 'seo-studio' ),
				'',
				'not_checked'
			);
		}

		$ratio = $page->visibleTextBytes / $page->htmlBytes;
		if ( $ratio >= 0.02 ) {
			$earned = 3;
		} elseif ( $ratio >= 0.01 ) {
			$earned = 1;
		} else {
			$earned = 0;
		}

		return AssessmentResult::score(
			$this,
			$earned,
			array( 'ratio' => $ratio ),
			sprintf(
				/* translators: %s: visible text percentage */
				__( 'Visible text is %s%% of the page source. The full score starts at 2%%.', 'seo-studio' ),
				number_format( $ratio * 100, 2, '.', '' )
			),
			3 === $earned
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Increase the share of visible text relative to the page source.', 'seo-studio' )
		);
	}
}
