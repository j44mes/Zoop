import { __ } from '@wordpress/i18n';

/**
 * Visible label for an overlap level.
 *
 * @param level Level id.
 */
export function overlapLevel( level: string ): string {
	switch ( level ) {
		case 'high':
			return __( 'High', 'seo-studio' );
		case 'medium':
			return __( 'Medium', 'seo-studio' );
		case 'low':
			return __( 'Low', 'seo-studio' );
		default:
			return __( 'Insufficient evidence', 'seo-studio' );
	}
}
