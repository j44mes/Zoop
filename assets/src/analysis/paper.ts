import type { PaperInput } from './score-paper';

export interface ModelShape {
	url?: string;
	slug?: string;
	title_tag?: string;
	meta_description?: string;
	body_text?: string;
	h1?: string[];
	headings?: Array< { level: number; text: string } >;
	paragraphs?: string[];
	images?: Array< { src: string; alt: string } >;
	links?: Array< { href: string; anchor: string } >;
	lang?: string;
}

function escapeHtml( value: string ): string {
	return value
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' );
}

/**
 * Rebuild a small HTML document from the extracted model.
 * The content engine reads structure, so headings and images are kept.
 * @param model
 */
export function htmlFromModel( model: ModelShape ): string {
	const headings = ( model.headings ?? [] )
		.map( ( heading ) => {
			const level = Math.min( 6, Math.max( 1, heading.level || 2 ) );
			return `<h${ level }>${ escapeHtml( heading.text ) }</h${ level }>`;
		} )
		.join( '' );
	const paragraphs = ( model.paragraphs ?? [] )
		.map( ( paragraph ) => `<p>${ escapeHtml( paragraph ) }</p>` )
		.join( '' );
	const images = ( model.images ?? [] )
		.map(
			( image ) =>
				`<img src="${ escapeHtml( image.src ) }" alt="${ escapeHtml(
					image.alt
				) }" />`
		)
		.join( '' );
	const links = ( model.links ?? [] )
		.map(
			( link ) =>
				`<a href="${ escapeHtml( link.href ) }">${ escapeHtml(
					link.anchor
				) }</a>`
		)
		.join( '' );
	const body =
		headings + paragraphs + images + links ||
		`<p>${ escapeHtml( model.body_text ?? '' ) }</p>`;
	return body;
}

export function paperFromModel(
	model: ModelShape,
	fields: {
		keyphrase: string;
		related?: string[];
		synonyms?: string[];
		usedElsewhere?: number[];
	}
): PaperInput {
	const locale = ( model.lang || 'en' ).replace( '-', '_' );
	return {
		html: htmlFromModel( model ),
		keyphrase: fields.keyphrase,
		relatedKeyphrases: fields.related ?? [],
		synonyms: fields.synonyms ?? [],
		title: model.title_tag ?? '',
		description: model.meta_description ?? '',
		slug: model.slug ?? '',
		permalink: model.url ?? '',
		locale: locale.includes( '_' ) ? locale : `${ locale }_US`,
		usedElsewhere: fields.usedElsewhere ?? [],
	};
}
