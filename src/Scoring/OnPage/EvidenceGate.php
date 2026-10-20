<?php
/**
 * Turns missing metadata or heading evidence into a withheld assessment.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Scoring\OnPage;

use SEOStudio\Content\MetadataEvidence;
use SEOStudio\Content\PageModel;

/**
 * Withheld checks stay out of the score and do not recommend a change.
 */
final class EvidenceGate {
	public static function metadata( Assessment $assessment, string $evidence ): ?AssessmentResult {
		if ( MetadataEvidence::NOT_OBSERVED === $evidence ) {
			return self::withhold(
				$assessment,
				'not_checked',
				__( 'This check did not run because the value was not observed.', 'seo-studio' )
			);
		}

		if ( MetadataEvidence::OBSERVED_ABSENT === $evidence ) {
			return self::withhold(
				$assessment,
				'not_applicable',
				__( 'This check does not apply because the tag is not in the page.', 'seo-studio' )
			);
		}

		return null;
	}

	/**
	 * Score a title that was fetched, including a page that has no title tag.
	 *
	 * An unobserved title stays outside the score. Meta descriptions keep the
	 * separate absent-tag rule in metadata().
	 */
	public static function fetchedTitle( Assessment $assessment, string $evidence ): ?AssessmentResult {
		if ( MetadataEvidence::NOT_OBSERVED === $evidence ) {
			return self::metadata( $assessment, $evidence );
		}

		return null;
	}

	public static function documentH1( Assessment $assessment, PageModel $page ): ?AssessmentResult {
		if ( MetadataEvidence::OBSERVED === $page->documentH1Evidence ) {
			return null;
		}

		return self::withhold(
			$assessment,
			'not_checked',
			__( 'This check did not run because the full-document H1s were not observed.', 'seo-studio' )
		);
	}

	public static function note( string $explanation, string $evidence ): string {
		if ( MetadataEvidence::CALCULATED !== $evidence ) {
			return $explanation;
		}

		return $explanation . ' ' . __( 'Calculated from the saved value. This is not fetched HTML.', 'seo-studio' );
	}

	private static function withhold( Assessment $assessment, string $status, string $explanation ): AssessmentResult {
		return AssessmentResult::score(
			$assessment,
			0,
			array( 'evidence_state' => $status ),
			$explanation,
			'',
			'',
			$status
		);
	}
}
