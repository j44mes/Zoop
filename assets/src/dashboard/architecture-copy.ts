import { __ } from '@wordpress/i18n';

/**
 * Short status label for a page in the architecture report.
 *
 * @param status Graph status.
 */
export function architectureStatus( status: string ): string {
	switch ( status ) {
		case 'front':
			return __( 'Front page', 'seo-studio' );
		case 'orphan':
			return __( 'Orphan', 'seo-studio' );
		case 'weak':
			return __( 'Weakly linked', 'seo-studio' );
		case 'template_only':
			return __( 'Template only', 'seo-studio' );
		case 'unknown':
			return __( 'Not enough data', 'seo-studio' );
		default:
			return __( 'Linked', 'seo-studio' );
	}
}

/**
 * Depth is unknown when neither the front page nor navigation reaches the page.
 *
 * @param depth Crawl depth, or null when the page was not reached.
 */
export function depthLabel( depth: number | null ): string {
	if ( depth === null ) {
		return __( 'Not reached', 'seo-studio' );
	}
	return String( depth );
}
