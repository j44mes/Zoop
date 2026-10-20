import { __ } from '@wordpress/i18n';

export type SuggestionStatus =
	| 'loading'
	| 'error'
	| 'unscored'
	| 'clear'
	| 'items';

/**
 * Whether the dashboard should show the single first-run card.
 * A filtered request that matches nothing is not a fresh site.
 *
 * @param stored   Permission-scoped stored page count.
 * @param filtered Whether the list request includes a search or status filter.
 */
export function isFirstRun( stored: number, filtered: boolean ): boolean {
	return stored <= 0 && ! filtered;
}

/**
 * First-run only after a list response names a stored count.
 * A missing summary is still loading, not an empty site.
 *
 * @param analysed Stored count from the list summary, when the response has one.
 * @param filtered Whether the list request includes a search or status filter.
 */
export function dashboardFirstRun(
	analysed: number | null | undefined,
	filtered: boolean
): boolean {
	return typeof analysed === 'number' && isFirstRun( analysed, filtered );
}

/**
 * Suggested-next-steps copy. An empty item list is not an all-clear.
 *
 * @param status Server suggestion state, or error when the request failed.
 */
export function suggestionMessage( status: SuggestionStatus ): string | null {
	if ( status === 'error' ) {
		return __( 'Suggestions could not be loaded.', 'seo-studio' );
	}
	if ( status === 'unscored' ) {
		return __(
			'No suggestions yet. Check a page to get some.',
			'seo-studio'
		);
	}
	if ( status === 'clear' ) {
		return __( 'Nothing to fix right now.', 'seo-studio' );
	}
	return null;
}

/**
 * Empty page-list copy. Filters and an empty site use different sentences.
 *
 * @param filtered Whether the list request includes a search or status filter.
 */
export function pagesListEmpty( filtered: boolean ): string {
	if ( filtered ) {
		return __( 'No stored pages match these filters.', 'seo-studio' );
	}
	return __(
		'No pages are stored yet. Open a page and choose Analyse page.',
		'seo-studio'
	);
}
