import { __ } from '@wordpress/i18n';

interface SocialPreviewProps {
	title: string;
	description: string;
	image?: string;
}

export function SocialPreview( {
	title,
	description,
	image,
}: SocialPreviewProps ) {
	return (
		<article
			className="seostudio-preview"
			aria-label={ __( 'Social preview', 'seo-studio' ) }
		>
			{ image ? (
				<img src={ image } alt="" />
			) : (
				<p>{ __( 'No image', 'seo-studio' ) }</p>
			) }
			<h3>{ title || __( 'Untitled', 'seo-studio' ) }</h3>
			<p>{ description || __( 'No description yet.', 'seo-studio' ) }</p>
		</article>
	);
}
