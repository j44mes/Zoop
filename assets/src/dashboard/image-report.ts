import { __ } from '@wordpress/i18n';

export type ImageKind = 'html' | 'template' | 'css_background' | 'unverified';

export interface ReportedImage {
	src?: string;
	alt?: string;
	in_body?: boolean;
	kind?: string;
	source?: string;
}

/**
 * Classification stored on the page model, with a fallback for older analyses.
 *
 * @param image One extracted image.
 */
export function imageKind( image: ReportedImage ): ImageKind {
	if (
		image.kind === 'html' ||
		image.kind === 'template' ||
		image.kind === 'css_background' ||
		image.kind === 'unverified'
	) {
		return image.kind;
	}
	return image.in_body ? 'html' : 'template';
}

/**
 * Short label for one classification.
 *
 * @param kind Image classification.
 */
export function imageKindLabel( kind: ImageKind ): string {
	const labels: Record< ImageKind, string > = {
		html: __( 'HTML image', 'seo-studio' ),
		template: __( 'Template image', 'seo-studio' ),
		css_background: __( 'CSS background', 'seo-studio' ),
		unverified: __( 'Not verified', 'seo-studio' ),
	};
	return labels[ kind ];
}

/**
 * What this analysis could and could not see.
 *
 * @param renderMode content or live.
 */
export function imageModeNote( renderMode: string ): string {
	if ( 'live' === renderMode ) {
		return __(
			'This check read the published HTML. Images outside the main content are template images. Stylesheets were not downloaded, so a background that exists only in a CSS file was not verified. A CSS background is not indexed from that CSS reference alone.',
			'seo-studio'
		);
	}
	return __(
		'This check read the saved page content. Header, footer, and other template images are absent until you check the published page. Neither check downloads stylesheets. A CSS background is not indexed from that CSS reference alone.',
		'seo-studio'
	);
}

/**
 * Advice for one image. CSS backgrounds are not treated as missing alt text.
 *
 * @param image One extracted image.
 */
export function imageNote( image: ReportedImage ): string {
	const kind = imageKind( image );
	if ( 'css_background' === kind ) {
		return __(
			'This address came from CSS. It has no alt text, and it is not part of the image-description score.',
			'seo-studio'
		);
	}
	if ( 'unverified' === kind ) {
		if ( 'stylesheet' === image.source ) {
			return __(
				'A stylesheet is linked from this page. Zoop did not download it.',
				'seo-studio'
			);
		}
		if ( 'divi' === image.source ) {
			return __(
				'This section is marked as a background, and this HTML does not include an image address.',
				'seo-studio'
			);
		}
		if ( 'style' === image.source ) {
			return __(
				'A style block was too large to read, so its backgrounds were not checked.',
				'seo-studio'
			);
		}
		return __(
			'An image element had no address Zoop could read.',
			'seo-studio'
		);
	}
	if ( ! image.alt ) {
		return __(
			'No description. Leave this empty when the image is decorative. Otherwise describe what it shows.',
			'seo-studio'
		);
	}
	return image.alt;
}
