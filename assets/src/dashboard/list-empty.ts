import { __ } from '@wordpress/i18n';

/**
 * Empty suggestion copy. A site with stored pages is not described as empty.
 *
 * @param stored Pages Zoop has stored for this user.
 * @param scored Pages with a current definitive score.
 */
export function suggestionEmpty( stored: number, scored: number ): string {
	if ( stored <= 0 ) {
		return __(
			'No stored pages yet. Open a page and choose Analyse page.',
			'seo-studio'
		);
	}
	if ( scored <= 0 ) {
		return __(
			'No pages have a score yet. Open a page and choose Check published page.',
			'seo-studio'
		);
	}
	return __( 'No suggested pages were stored.', 'seo-studio' );
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
