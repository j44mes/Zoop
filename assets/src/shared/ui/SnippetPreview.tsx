import { __ } from '@wordpress/i18n';

interface SnippetPreviewProps {
	title: string;
	url: string;
	description: string;
	viewport?: 'desktop' | 'mobile';
}

export function SnippetPreview( {
	title,
	url,
	description,
	viewport = 'desktop',
}: SnippetPreviewProps ) {
	let host = url || __( 'Permalink unavailable', 'seo-studio' );
	try {
		if ( url ) {
			host = new URL( url ).host;
		}
	} catch ( _error ) {
		host = url;
	}
	return (
		<article
			className="seostudio-preview seostudio-serp"
			data-viewport={ viewport }
			aria-label={ __( 'Search snippet preview', 'seo-studio' ) }
		>
			<p className="seostudio-serp-url">
				<span className="seostudio-serp-mark" aria-hidden="true" />
				{ host }
			</p>
			<h3>{ title || __( 'Untitled', 'seo-studio' ) }</h3>
			<p>{ description || __( 'No description yet.', 'seo-studio' ) }</p>
		</article>
	);
}
