<?php
/**
 * Decides whether a stored on-page result may be shown as definitive.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Scoring\OnPage;

use SEOStudio\Content\MetadataEvidence;
use SEOStudio\Content\PageModel;

/**
 * The headline gate. Points and the denominator are unchanged.
 */
final class ScorePresentation {
	/**
	 * Gate result for one scored model.
	 *
	 * @param array{status: string, source: string} $index Index status and source.
	 * @return array{ready: bool, missing: string, checks_evaluated: int, checks_applicable: int, index_status: string, index_source: string}
	 */
	public static function gate( PageModel $page, Score $score, string $keyphrase, array $index ): array {
		$missing = array();
		if ( ! in_array( $index['status'], array( 'indexable', 'noindex', 'not_public', 'site_discouraged' ), true ) ) {
			$missing[] = 'indexability';
		}
		if ( ! in_array( $page->titleEvidence, array( MetadataEvidence::OBSERVED, MetadataEvidence::OBSERVED_ABSENT ), true ) ) {
			$missing[] = 'title';
		}
		if ( MetadataEvidence::OBSERVED !== $page->documentH1Evidence ) {
			$missing[] = 'h1';
		}

		$evaluated = false;
		foreach ( $score->assessments as $assessment ) {
			if ( 'title.entire_keyphrase' === $assessment->id && in_array( $assessment->status, array( 'pass', 'fail' ), true ) ) {
				$evaluated = true;
			}
		}
		if ( '' === trim( $keyphrase ) || ! $evaluated ) {
			$missing[] = 'keyphrase';
		}

		$evaluated_count = 0;
		$applicable      = 0;
		foreach ( $score->assessments as $assessment ) {
			if ( 'not_applicable' === $assessment->status ) {
				continue;
			}
			++$applicable;
			if ( in_array( $assessment->status, array( 'pass', 'partial', 'fail' ), true ) ) {
				++$evaluated_count;
			}
		}

		return array(
			'ready'             => array() === $missing && 'live' === $page->renderMode,
			'missing'           => implode( ',', $missing ),
			'checks_evaluated'  => $evaluated_count,
			'checks_applicable' => $applicable,
			'index_status'      => $index['status'],
			'index_source'      => $index['source'],
		);
	}

	/**
	 * Headline payload for a list or page row.
	 *
	 * @param array<string, mixed> $row Page row columns.
	 * @return array<string, mixed>
	 */
	public static function fromRow( array $row ): array {
		$state    = (string) ( $row['onpage_state'] ?? 'not_analysed' );
		$ready    = 'current' === $state && 1 === (int) ( $row['evidence_ready'] ?? 0 );
		$snapshot = 1 === (int) ( $row['has_published_snapshot'] ?? 0 );
		$score    = isset( $row['onpage_score'] ) ? (int) $row['onpage_score'] : null;
		$den      = isset( $row['onpage_denominator'] ) ? (int) $row['onpage_denominator'] : null;
		$x        = (int) ( $row['checks_evaluated'] ?? 0 );
		$y        = (int) ( $row['checks_applicable'] ?? 0 );
		$missing  = self::missingItems( (string) ( $row['missing_evidence'] ?? '' ) );
		$verified = (string) ( $row['verified_at'] ?? '' );
		$headline = __( 'Not enough evidence yet', 'seo-studio' );
		if ( 'needs_reanalysis' === $state ) {
			$headline = __( 'Out of date', 'seo-studio' );
		} elseif ( $ready && null !== $score && null !== $den ) {
			$headline = $score . ' / ' . $den;
		}

		$index = (string) ( $row['index_status'] ?? 'unobserved' );

		return array(
			'definitive'         => $ready,
			'historical'         => $snapshot && ! $ready,
			'headline'           => $headline,
			'explanation'        => $ready
				? sprintf(
					/* translators: 1: checks evaluated, 2: applicable checks. */
					__( 'Based on %1$d of %2$d applicable checks.', 'seo-studio' ),
					$x,
					$y
				)
				: '',
			'checks_evaluated'   => $x,
			'checks_applicable'  => $y,
			'missing'            => $missing,
			'verified_label'     => '' === $verified
				? ''
				: sprintf(
					/* translators: %s: local datetime of the last published-page check. */
					__( 'Published HTML last verified %s. This is not a continuous live check.', 'seo-studio' ),
					$verified
				),
			'search_status'      => $index,
			'search_label'       => self::searchLabel( $index ),
			'search_explanation' => self::searchExplanation( $index, (string) ( $row['index_source'] ?? '' ) ),
			'content_rating'     => (string) ( $row['onpage_rating'] ?? '' ),
		);
	}

	/**
	 * Missing-evidence lines for the stored codes.
	 *
	 * @return array<int, array{id: string, label: string, action: string, action_label: string}>
	 */
	public static function missingItems( string $codes ): array {
		$labels = array(
			'indexability' => __( 'Indexability has not been checked.', 'seo-studio' ),
			'title'        => __( 'The published page title has not been observed.', 'seo-studio' ),
			'h1'           => __( 'The full-document H1 has not been observed.', 'seo-studio' ),
			'keyphrase'    => __( 'The focus keyphrase has not been checked in an observed title.', 'seo-studio' ),
			'ruleset'      => __( 'Scores need checking against the current rules.', 'seo-studio' ),
		);
		$items  = array();
		foreach ( array_filter( explode( ',', $codes ) ) as $code ) {
			$action  = 'keyphrase' === $code ? 'add_keyphrase' : 'check_published';
			$items[] = array(
				'id'           => $code,
				'label'        => $labels[ $code ] ?? $code,
				'action'       => $action,
				'action_label' => CheckPresentation::actionLabel( $action ),
			);
		}

		return $items;
	}

	/**
	 * Suggestion outcome for one authorised population.
	 *
	 * All-clear requires every stored page in that population to be current,
	 * definitively scored, and evidence-ready, with no actionable finding.
	 *
	 * @param int $checked    Pages with a definitive ready score.
	 * @param int $actionable Definitive pages whose stored checks include a fail or partial.
	 * @param int $unchecked  Stored pages that are not definitive. Stale, unscored, unanalysed, and current-but-not-ready rows all count.
	 */
	public static function suggestionState( int $checked, int $actionable, int $unchecked ): string {
		if ( $checked < 1 ) {
			return 'unscored';
		}
		if ( $actionable > 0 ) {
			return 'items';
		}
		if ( $unchecked > 0 ) {
			return 'incomplete';
		}
		return 'clear';
	}

	/**
	 * Scoped result when the checked pages have no findings and other stored pages do not.
	 *
	 * @param int $checked   Pages with a definitive ready score.
	 * @param int $unchecked Stored pages that are not definitive.
	 */
	public static function incompleteNotice( int $checked, int $unchecked ): string {
		$checked   = max( 0, $checked );
		$unchecked = max( 0, $unchecked );

		return sprintf(
			/* translators: 1: number of checked pages, 2: sentence naming how many stored pages have not been checked. */
			_n(
				'No fixes found on the %1$d checked page. %2$s',
				'No fixes found on the %1$d checked pages. %2$s',
				$checked,
				'seo-studio'
			),
			$checked,
			sprintf(
				/* translators: %d: number of stored pages that do not have a definitive ready score. */
				_n(
					'%d page hasn\'t been checked yet.',
					'%d pages haven\'t been checked yet.',
					$unchecked,
					'seo-studio'
				),
				$unchecked
			)
		);
	}

	/**
	 * Sentence for stored pages that are not definitive, used beside suggestion cards.
	 *
	 * @param int $unchecked Stored pages that are not definitive.
	 */
	public static function uncheckedNotice( int $unchecked ): string {
		$unchecked = max( 0, $unchecked );

		return sprintf(
			/* translators: %d: number of stored pages that do not have a definitive ready score. */
			_n(
				'%d page hasn\'t been checked yet.',
				'%d pages haven\'t been checked yet.',
				$unchecked,
				'seo-studio'
			),
			$unchecked
		);
	}

	public static function reanalysisNotice( int $count ): string {
		$count = max( 0, $count );

		return sprintf(
			/* translators: %d: number of pages that need another published-page check. */
			_n(
				'%d page has changed or needs checking again.',
				'%d pages have changed or need checking again.',
				$count,
				'seo-studio'
			),
			$count
		);
	}

	public static function searchLabel( string $status ): string {
		$labels = array(
			'noindex'          => __( 'Hidden from search', 'seo-studio' ),
			'indexable'        => __( 'Indexable', 'seo-studio' ),
			'not_public'       => __( 'This page is not public', 'seo-studio' ),
			'site_discouraged' => __( 'The site asks search engines to stay away', 'seo-studio' ),
			'unobserved'       => __( 'Indexability has not been checked', 'seo-studio' ),
		);

		return $labels[ $status ] ?? $labels['unobserved'];
	}

	public static function searchExplanation( string $status, string $source ): string {
		if ( 'noindex' === $status ) {
			$where = 'meta+http' === $source
				? __( 'the meta robots tag and the X-Robots-Tag header', 'seo-studio' )
				: ( 'http' === $source ? __( 'the X-Robots-Tag header', 'seo-studio' ) : __( 'the meta robots tag', 'seo-studio' ) );
			return sprintf(
				/* translators: %s: where the noindex directive was observed. */
				__( 'This page asks to be left out of search results via %s. That does not mean a search engine has already removed it. The choice may be intentional. The content score is unchanged.', 'seo-studio' ),
				$where
			);
		}
		if ( 'indexable' === $status ) {
			return __( 'The last published-page check found no noindex directive.', 'seo-studio' );
		}
		if ( 'not_public' === $status ) {
			return __( 'WordPress is not showing this page publicly. That is separate from a noindex directive.', 'seo-studio' );
		}
		if ( 'site_discouraged' === $status ) {
			return __( 'The site setting asks search engines to stay away. Zoop has not observed a noindex directive on this page.', 'seo-studio' );
		}

		return __( 'Zoop has not read this page’s published robots directives. robots.txt and a different canonical address are not treated as noindex.', 'seo-studio' );
	}
}
