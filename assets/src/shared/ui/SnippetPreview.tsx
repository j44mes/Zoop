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
	return (
		<article
			className="seostudio-preview"
			data-viewport={ viewport }
			aria-label={ __( 'Google preview', 'seo-studio' ) }
		>
			<p>{ url || __( 'Permalink unavailable', 'seo-studio' ) }</p>
			<h3>{ title || __( 'Untitled', 'seo-studio' ) }</h3>
			<p>{ description || __( 'No description yet.', 'seo-studio' ) }</p>
		</article>
	);
}
