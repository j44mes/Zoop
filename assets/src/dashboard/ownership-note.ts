import { __, sprintf } from '@wordpress/i18n';

interface OwnershipSettings {
	publishing?: boolean;
	detected?: { name?: string } | null;
}

/**
 * Say who prints the live tags. Saved Zoop fields are not described as the published result.
 *
 * @param settings Current Zoop settings, including the detector summary.
 */
export function workspaceOwnership(
	settings: OwnershipSettings | null
): string {
	if ( settings?.publishing ) {
		return __(
			'Zoop manages the supported search and social tags. A content analysis is not a check of the published page.',
			'seo-studio'
		);
	}
	if ( settings?.detected?.name ) {
		return sprintf(
			/* translators: %s: name of the active SEO plugin. */
			__(
				'%s controls the live tags. Fields saved in Zoop are not the published metadata.',
				'seo-studio'
			),
			settings.detected.name
		);
	}
	return __(
		'Analysis-only. Zoop saves its fields and does not print them.',
		'seo-studio'
	);
}

/**
 * Caption for an illustrative search-result preview.
 */
export function appearancePreviewCaption(): string {
	return __(
		'Illustrative preview. Google may rewrite the title and description.',
		'seo-studio'
	);
}

/**
 * Caption for the snippet taken from the stored page model.
 *
 * @param renderMode content or live, from the stored page row.
 */
export function snippetCaption( renderMode: string ): string {
	if ( renderMode === 'live' ) {
		return __(
			'Observed on the last check of the published page.',
			'seo-studio'
		);
	}
	return __(
		'Taken from the last content analysis. This is not the live metadata.',
		'seo-studio'
	);
}

/**
 * Caption for Zoop's saved social fields.
 *
 * @param publishing Whether Zoop is currently printing tags.
 */
export function socialCaption( publishing: boolean ): string {
	if ( publishing ) {
		return __(
			'Saved Zoop social fields. Zoop can print these when it manages metadata.',
			'seo-studio'
		);
	}
	return __(
		'Saved Zoop social fields. They are not the card published on the live page.',
		'seo-studio'
	);
}
