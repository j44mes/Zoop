import { __ } from '@wordpress/i18n';

export function priorityKind( kind: string ): string {
	const labels: Record< string, string > = {
		overlap: __( 'Overlap', 'seo-studio' ),
		unresolved: __( 'Unresolved link', 'seo-studio' ),
		page: __( 'Link graph', 'seo-studio' ),
		search: __( 'Search evidence', 'seo-studio' ),
	};
	return labels[ kind ] || kind;
}
