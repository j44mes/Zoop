import { __ } from '@wordpress/i18n';

export interface EditTarget {
	section: string;
	label: string;
	liveCheck: boolean;
}

/**
 * Point a finding at an existing editor control. Code checks have no field to edit.
 *
 * @param assessmentId On-page assessment id.
 */
export function editTarget( assessmentId: string ): EditTarget {
	if ( assessmentId.startsWith( 'code.' ) ) {
		return {
			section: '',
			label: __(
				'Use Check published page. This check needs the published page.',
				'seo-studio'
			),
			liveCheck: true,
		};
	}
	if (
		assessmentId.startsWith( 'title.' ) ||
		assessmentId.startsWith( 'meta.' ) ||
		assessmentId === 'headings.title_differs'
	) {
		return {
			section: 'search',
			label: __( 'Edit the SEO title and description', 'seo-studio' ),
			liveCheck: false,
		};
	}
	if ( assessmentId.startsWith( 'url.' ) ) {
		return {
			section: 'search',
			label: __( 'Edit the canonical address', 'seo-studio' ),
			liveCheck: false,
		};
	}
	if (
		assessmentId.startsWith( 'headings.' ) ||
		assessmentId.startsWith( 'body.' ) ||
		assessmentId.startsWith( 'media.' )
	) {
		return {
			section: '',
			label: __( 'Edit the page content', 'seo-studio' ),
			liveCheck: false,
		};
	}
	return {
		section: 'phrases',
		label: __( 'Edit the target phrase and related phrases', 'seo-studio' ),
		liveCheck: false,
	};
}

/**
 * Editor URL that opens the existing Zoop disclosure for this finding.
 *
 * @param postId       WordPress post id.
 * @param assessmentId On-page assessment id.
 */
export function editHref( postId: number, assessmentId: string ): string {
	const target = editTarget( assessmentId );
	const params = new URLSearchParams( {
		post: String( postId ),
		action: 'edit',
	} );
	if ( target.section ) {
		params.set( 'seostudio_section', target.section );
	}
	return `post.php?${ params.toString() }`;
}
