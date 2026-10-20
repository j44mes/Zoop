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
			return __( 'Not yet verified', 'seo-studio' );
		default:
			return __( 'Linked', 'seo-studio' );
	}
}

/**
 * Depth label. A null depth is a missing path only when coverage is complete.
 *
 * @param depth Crawl depth, or null when no path was verified.
 * @param known True when published-link coverage is complete.
 */
export function depthLabel( depth: number | null, known = true ): string {
	if ( depth === null ) {
		return known
			? __( 'No verified path', 'seo-studio' )
			: __( 'Depth not known yet', 'seo-studio' );
	}
	return String( depth );
}

/**
 * Orphan total. Null means the count is withheld, not that the total is zero.
 *
 * @param orphans Orphan count, or null when coverage is incomplete.
 */
export function orphanCountLabel( orphans: number | null ): string {
	if ( orphans === null ) {
		return __( 'Not counted yet', 'seo-studio' );
	}
	return String( orphans );
}

/**
 * Aggregate depth. Null means Zoop will not claim how many pages lack a path.
 *
 * @param unreachable Pages with no verified path, or null when coverage is incomplete.
 */
export function depthCountLabel( unreachable: number | null ): string {
	if ( unreachable === null ) {
		return __( 'Not known yet', 'seo-studio' );
	}
	return String( unreachable );
}
