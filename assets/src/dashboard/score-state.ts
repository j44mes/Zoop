import { __, sprintf } from '@wordpress/i18n';

export type ScoreState =
	| 'current'
	| 'needs_reanalysis'
	| 'unscored'
	| 'not_analysed'
	| '';

/**
 * Label for a stored on-page result. Stale numbers are not shown as the current score.
 *
 * @param state       Stored score state.
 * @param score       Current earned points, when the state is current.
 * @param denominator Current points available, when the state is current.
 */
export function scoreStateLabel(
	state: ScoreState | undefined,
	score: number | null,
	denominator: number | null
): string {
	if ( state === 'needs_reanalysis' ) {
		return __( 'Out of date', 'seo-studio' );
	}
	if ( state === 'unscored' ) {
		return __( 'Not scored', 'seo-studio' );
	}
	if (
		state === 'not_analysed' ||
		state === '' ||
		state === undefined ||
		score === null ||
		denominator === null
	) {
		return __( 'Not analysed yet', 'seo-studio' );
	}
	return `${ score } / ${ denominator }`;
}

/**
 * Suggested next step. A stale score never becomes the recommendation.
 *
 * @param state      Stored score state.
 * @param topIssue   Assessment id for a current score.
 * @param issueTitle Translated name of that assessment.
 */
export function suggestionForState(
	state: ScoreState | undefined,
	topIssue: string,
	issueTitle: string
): string {
	if ( state === 'needs_reanalysis' ) {
		return __( 'Out of date.', 'seo-studio' );
	}
	if ( state === 'current' && topIssue ) {
		return issueTitle;
	}
	if ( state === 'unscored' ) {
		return __(
			'This page has been rendered but not scored.',
			'seo-studio'
		);
	}
	if ( state === 'current' ) {
		return __( 'No outstanding on-page finding was stored.', 'seo-studio' );
	}
	return __( 'This page has not been analysed yet.', 'seo-studio' );
}

/**
 * Previous result kept after the scoring rules changed.
 *
 * @param score       Points from the previous on-page result.
 * @param denominator Points available in that previous result.
 */
export function historicalScoreLabel(
	score: number,
	denominator: number
): string {
	return sprintf(
		/* translators: 1: previous points, 2: previous points available. */
		__( 'Previous result: %1$d / %2$d. Out of date.', 'seo-studio' ),
		score,
		denominator
	);
}
