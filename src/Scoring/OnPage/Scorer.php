<?php
/**
 * On-page scorer.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Scoring\OnPage;

use SEOStudio\Content\PageModel;
use SEOStudio\Scoring\OnPage\Assessments\BodyEveryWord;
use SEOStudio\Scoring\OnPage\Assessments\BodyMentions;
use SEOStudio\Scoring\OnPage\Assessments\BodyPartial;
use SEOStudio\Scoring\OnPage\Assessments\BodyWordCount;
use SEOStudio\Scoring\OnPage\Assessments\CodeStylesheet;
use SEOStudio\Scoring\OnPage\Assessments\CodeVisibleRatio;
use SEOStudio\Scoring\OnPage\Assessments\HeadingCount;
use SEOStudio\Scoring\OnPage\Assessments\HeadingEntireKeyphrase;
use SEOStudio\Scoring\OnPage\Assessments\HeadingLength;
use SEOStudio\Scoring\OnPage\Assessments\HeadingPartialKeyphrase;
use SEOStudio\Scoring\OnPage\Assessments\HeadingPosition;
use SEOStudio\Scoring\OnPage\Assessments\HeadingSubheadings;
use SEOStudio\Scoring\OnPage\Assessments\HeadingTitleDiffers;
use SEOStudio\Scoring\OnPage\Assessments\MediaAlt;
use SEOStudio\Scoring\OnPage\Assessments\MetaKeyphrase;
use SEOStudio\Scoring\OnPage\Assessments\TitleEntireKeyphrase;
use SEOStudio\Scoring\OnPage\Assessments\TitleExists;
use SEOStudio\Scoring\OnPage\Assessments\TitleLength;
use SEOStudio\Scoring\OnPage\Assessments\TitlePartialKeyphrase;
use SEOStudio\Scoring\OnPage\Assessments\TitlePosition;
use SEOStudio\Scoring\OnPage\Assessments\UrlHttps;
use SEOStudio\Scoring\OnPage\Assessments\UrlKeyphrase;

/**
 * Sums the registered assessments for one keyphrase.
 */
final class Scorer {
	public function __construct(
		private readonly Normaliser $normaliser = new Normaliser(),
		private readonly Advisories $advisories = new Advisories()
	) {}

	public function score( PageModel $page, string $keyphrase ): Score {
		$earned      = 0;
		$denominator = 0;
		$results     = array();
		$categories  = array();

		foreach ( $this->assessments() as $assessment ) {
			$result    = $assessment->evaluate( $page, $keyphrase, $this->normaliser );
			$results[] = $result;
			$category  = $result->category;

			if ( ! isset( $categories[ $category ] ) ) {
				$categories[ $category ] = array(
					'earned' => 0,
					'max'    => 0,
				);
			}

			if ( 'not_checked' === $result->status ) {
				continue;
			}

			$earned                            += $result->earned;
			$denominator                       += $result->max;
			$categories[ $category ]['earned'] += $result->earned;
			$categories[ $category ]['max']    += $result->max;
		}

		return new Score(
			$earned,
			$denominator,
			$results,
			$categories,
			$this->advisories->forPage( $page, $keyphrase, $this->normaliser )
		);
	}

	/**
	 * Return the registered assessments, after filters.
	 *
	 * @return Assessment[]
	 */
	public function assessments(): array {
		$assessments = array(
			new TitleExists(),
			new TitleEntireKeyphrase(),
			new TitlePartialKeyphrase(),
			new TitleLength(),
			new TitlePosition(),
			new HeadingEntireKeyphrase(),
			new HeadingPartialKeyphrase(),
			new HeadingLength(),
			new HeadingPosition(),
			new HeadingCount(),
			new HeadingTitleDiffers(),
			new HeadingSubheadings(),
			new BodyWordCount(),
			new BodyMentions(),
			new BodyPartial(),
			new BodyEveryWord(),
			new MetaKeyphrase(),
			new MediaAlt(),
			new CodeStylesheet(),
			new CodeVisibleRatio(),
			new UrlKeyphrase(),
			new UrlHttps(),
		);

		$filtered = apply_filters( 'seostudio/assessments', $assessments ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Published filter name.
		if ( ! is_array( $filtered ) ) {
			return $assessments;
		}

		return array_values(
			array_filter(
				$filtered,
				static function ( $assessment ): bool {
					return $assessment instanceof Assessment;
				}
			)
		);
	}
}
