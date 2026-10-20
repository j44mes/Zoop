import { __ } from '@wordpress/i18n';

export interface CandidateDisclosure {
	post_id: number;
	title: string;
	url: string;
	reason?: string;
	redacted?: boolean;
}

/**
 * Title (reason), or an authorised URL when the page has no title.
 * Restricted pages contribute nothing.
 *
 * @param candidate Stored candidate the viewer is allowed to see.
 */
export function candidateLabel( candidate: CandidateDisclosure ): string {
	if ( candidate.redacted ) {
		return '';
	}
	const title = candidate.title.trim();
	const reason = ( candidate.reason ?? '' ).trim();
	if ( title ) {
		return reason ? `${ title } (${ reason })` : title;
	}
	if ( candidate.post_id === 0 && candidate.url.trim() ) {
		return candidate.url.trim();
	}
	return '';
}

export function priorityKind( kind: string ): string {
	const labels: Record< string, string > = {
		overlap: __( 'Overlap', 'seo-studio' ),
		unresolved: __( 'Unresolved link', 'seo-studio' ),
		unverified: __( 'Unverified address', 'seo-studio' ),
		page: __( 'Link graph', 'seo-studio' ),
		search: __( 'Search evidence', 'seo-studio' ),
		visibility: __( 'Search visibility', 'seo-studio' ),
	};
	return labels[ kind ] || kind;
}
