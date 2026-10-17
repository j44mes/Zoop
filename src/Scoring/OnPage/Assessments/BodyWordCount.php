<?php
/**
 * Body word count.
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
 * 500 or more words earns 14. The recommendation does not ask the writer to pad.
 */
final class BodyWordCount implements Assessment {
	public function id(): string {
		return 'body.word_count';
	}

	public function category(): string {
		return 'body';
	}

	public function max(): int {
		return 14;
	}

	public function evaluate( PageModel $page, string $keyphrase, Normaliser $normaliser ): AssessmentResult {
		unset( $keyphrase );
		$count = $normaliser->wordCount( $page->bodyText );
		if ( $count >= 500 ) {
			$earned = 14;
		} elseif ( $count >= 300 ) {
			$earned = 10;
		} elseif ( $count >= 150 ) {
			$earned = 6;
		} elseif ( $count >= 1 ) {
			$earned = 2;
		} else {
			$earned = 0;
		}

		return AssessmentResult::score(
			$this,
			$earned,
			array( 'words' => $count ),
			sprintf(
				/* translators: %d: body word count */
				__( 'The body contains %d words. The full score starts at 500.', 'seo-studio' ),
				$count
			),
			14 === $earned
				? __( 'No change needed.', 'seo-studio' )
				: __( 'Cover the topic fully. Do not add words only to raise this score.', 'seo-studio' ),
			''
		);
	}
}
