import { __ } from '@wordpress/i18n';

const titles: Record< string, string > = {
	'title.exists': __( 'SEO title', 'seo-studio' ),
	'title.entire_keyphrase': __( 'Target phrase in SEO title', 'seo-studio' ),
	'title.partial_keyphrase': __(
		'Part of the target phrase in the SEO title',
		'seo-studio'
	),
	'title.length': __( 'SEO title length', 'seo-studio' ),
	'title.position': __(
		'Target phrase near the start of the SEO title',
		'seo-studio'
	),
	'meta.keyphrase': __( 'Target phrase in description', 'seo-studio' ),
	'headings.h1_count': __( 'Main page heading', 'seo-studio' ),
	'headings.entire_keyphrase': __(
		'Target phrase in the main heading',
		'seo-studio'
	),
	'headings.partial_keyphrase': __(
		'Part of the target phrase in a heading',
		'seo-studio'
	),
	'headings.h1_length': __( 'Main heading length', 'seo-studio' ),
	'headings.position': __(
		'Target phrase near the start of the heading',
		'seo-studio'
	),
	'headings.subheadings': __( 'Subheadings', 'seo-studio' ),
	'headings.title_differs': __(
		'Heading and SEO title differ',
		'seo-studio'
	),
	'body.word_count': __( 'Content length', 'seo-studio' ),
	'body.mentions': __( 'Target phrase in the content', 'seo-studio' ),
	'body.partial': __(
		'Part of the target phrase in the content',
		'seo-studio'
	),
	'body.every_word': __( 'Target phrase words in the content', 'seo-studio' ),
	'url.keyphrase': __( 'Target phrase in the address', 'seo-studio' ),
	'url.https': __( 'Secure address', 'seo-studio' ),
	'media.alt': __( 'Image descriptions', 'seo-studio' ),
	'code.stylesheet': __( 'Stylesheet', 'seo-studio' ),
	'code.visible_ratio': __( 'Visible content', 'seo-studio' ),
	keyphraseDensity: __( 'Target phrase frequency', 'seo-studio' ),
};

/**
 * Readable name for an assessment. The id itself stays available in the details.
 *
 * @param id Assessment identifier from the scorer.
 */
export function assessmentTitle( id: string ): string {
	if ( titles[ id ] ) {
		return titles[ id ];
	}
	const words = id.replace( /[._]/g, ' ' ).trim();
	return words.charAt( 0 ).toUpperCase() + words.slice( 1 );
}
