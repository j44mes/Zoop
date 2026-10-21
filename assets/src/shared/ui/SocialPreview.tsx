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
			className="seostudio-preview seostudio-social"
			aria-label={ __( 'Social preview', 'seo-studio' ) }
		>
			<div className="seostudio-social-media">
				{ image ? (
					<img src={ image } alt="" />
				) : (
					<p>{ __( 'No image', 'seo-studio' ) }</p>
				) }
			</div>
			<div>
				<p className="seostudio-serp-url">
					{ __( 'Saved Zoop fields', 'seo-studio' ) }
				</p>
				<h3>{ title || __( 'Untitled', 'seo-studio' ) }</h3>
				<p>
					{ description || __( 'No description yet.', 'seo-studio' ) }
				</p>
			</div>
		</article>
	);
}
