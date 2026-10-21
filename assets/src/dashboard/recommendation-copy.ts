import { __ } from '@wordpress/i18n';
import { assessmentTitle } from '../shared/ui/assessment-labels';

/**
 * Card heading for one finding. The assessment sentence stays in the body.
 *
 * @param assessmentId On-page assessment id.
 */
export function recommendationHeading( assessmentId: string ): string {
	if (
		assessmentId.startsWith( 'title.' ) ||
		assessmentId === 'headings.title_differs'
	) {
		return __( 'Review the SEO title', 'seo-studio' );
	}
	if ( assessmentId.startsWith( 'meta.' ) ) {
		return __( 'Review the description', 'seo-studio' );
	}
	if ( assessmentId.startsWith( 'headings.' ) ) {
		return __( 'Review the headings', 'seo-studio' );
	}
	if ( assessmentId.startsWith( 'body.' ) ) {
		return __( 'Review the page content', 'seo-studio' );
	}
	if ( assessmentId.startsWith( 'media.' ) ) {
		return __( 'Review the images', 'seo-studio' );
	}
	if ( assessmentId.startsWith( 'url.' ) ) {
		return __( 'Review the address', 'seo-studio' );
	}
	if ( assessmentId.startsWith( 'code.' ) ) {
		return __( 'Review the published page', 'seo-studio' );
	}
	return assessmentTitle( assessmentId );
}

/**
 * Say why a check is worth reading, without promising rankings or traffic.
 *
 * @param assessmentId On-page assessment id.
 */
export function whyItMatters( assessmentId: string ): string {
	if (
		assessmentId.startsWith( 'title.' ) ||
		assessmentId.startsWith( 'meta.' ) ||
		assessmentId === 'headings.title_differs'
	) {
		return __(
			'The title and description are what a search result can show. Zoop is not predicting a ranking.',
			'seo-studio'
		);
	}
	if (
		assessmentId.startsWith( 'headings.' ) ||
		assessmentId.startsWith( 'body.' )
	) {
		return __(
			'Headings and the body help a reader recognise the page. A missing phrase is a prompt to review the writing, not an instruction to repeat it.',
			'seo-studio'
		);
	}
	if ( assessmentId.startsWith( 'media.' ) ) {
		return __(
			'An image description helps people who cannot see the image. It does not by itself change search placement.',
			'seo-studio'
		);
	}
	if ( assessmentId.startsWith( 'code.' ) ) {
		return __(
			'These checks read the published page. A content analysis cannot score them.',
			'seo-studio'
		);
	}
	if ( assessmentId.startsWith( 'url.' ) ) {
		return __(
			'The address identifies the page. Matching a phrase in the URL is optional.',
			'seo-studio'
		);
	}
	return __(
		'This is one of Zoop’s on-page checks. It is not a measure of traffic or rankings.',
		'seo-studio'
	);
}
