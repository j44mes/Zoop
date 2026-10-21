import { __, sprintf } from '@wordpress/i18n';

export interface MetadataOwner {
	publishesOwn: boolean;
	activePlugin: string;
	pluginUrl?: string;
	canManage?: boolean;
	isHomepage?: boolean;
	homepage?: {
		title_tag: string;
		meta_description: string;
		canonical: string;
	} | null;
}

export function metadataNote( metadata?: MetadataOwner ): string {
	if ( metadata?.publishesOwn && metadata.isHomepage ) {
		return metadata.canManage
			? __(
					'This is the homepage. Its title and description are Zoop settings. Change them in Zoop settings. This page does not publish a separate SEO title.',
					'seo-studio'
			  )
			: __(
					'This is the homepage. Its title and description are site settings. An administrator changes them in Zoop settings.',
					'seo-studio'
			  );
	}
	if ( metadata?.publishesOwn ) {
		return __(
			'Update publishes the SEO title and description. Zoop prints them on the published page.',
			'seo-studio'
		);
	}
	if ( metadata?.activePlugin ) {
		return sprintf(
			/* translators: %s: name of the active SEO plugin. */
			__(
				'%s prints the live title and description. Change them there. Zoop will not write a second set of tags.',
				'seo-studio'
			),
			metadata.activePlugin
		);
	}
	return __(
		'Zoop is not printing tags. An administrator can turn on Owner mode in Zoop settings.',
		'seo-studio'
	);
}
