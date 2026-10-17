import { __, sprintf } from '@wordpress/i18n';

export interface MetadataOwner {
	publishesOwn: boolean;
	activePlugin: string;
	isHomepage?: boolean;
	homepage?: {
		title_tag: string;
		meta_description: string;
		canonical: string;
	} | null;
}
export function metadataNote( metadata?: MetadataOwner ): string {
	if ( metadata?.publishesOwn ) {
		if ( metadata.isHomepage ) {
			return __(
				'This is your homepage. Scores and published metadata use Home title and Home description in Zoop Settings, and the site home URL as canonical. The page SEO fields below are saved but do not override those settings. Social overrides still apply.',
				'seo-studio'
			);
		}
		return __(
			'Scores use your SEO title and description, estimated from your settings. Verify live checks the published page.',
			'seo-studio'
		);
	}
	if ( metadata?.activePlugin ) {
		return sprintf(
			/* translators: %s: name of the active SEO plugin. */
			__(
				'%s prints this page’s metadata, so scores use its title and description, estimated from its settings. The SEO title and description below are saved but not printed.',
				'seo-studio'
			),
			metadata.activePlugin
		);
	}
	return __(
		'Analysis-only mode: the page prints its post title and excerpt, so scores use those. The SEO title and description below are saved but not printed.',
		'seo-studio'
	);
}
