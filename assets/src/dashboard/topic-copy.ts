import { __ } from '@wordpress/i18n';

/**
 * Visible label for a stored or suggested page role.
 *
 * @param role Role id.
 */
export function topicRoleLabel( role: string ): string {
	switch ( role ) {
		case 'hub':
			return __( 'Hub', 'seo-studio' );
		case 'supporting':
			return __( 'Supporting', 'seo-studio' );
		case 'utility':
			return __( 'Utility', 'seo-studio' );
		default:
			return __( 'Unassigned', 'seo-studio' );
	}
}

/**
 * Visible label for a suggested search intent.
 *
 * @param intent Intent id.
 */
export function topicIntentLabel( intent: string ): string {
	switch ( intent ) {
		case 'informational':
			return __( 'Informational', 'seo-studio' );
		case 'commercial':
			return __( 'Commercial', 'seo-studio' );
		case 'navigational':
			return __( 'Navigational', 'seo-studio' );
		default:
			return __( 'Unassigned', 'seo-studio' );
	}
}
