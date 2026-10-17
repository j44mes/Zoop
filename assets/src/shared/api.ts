import apiFetch from '@wordpress/api-fetch';
import type { ListedAssessment } from './ui/AssessmentList';

export interface OnPageScore {
	earned: number;
	denominator: number;
	assessments: ListedAssessment[];
	categories: Record< string, { earned: number; max: number } >;
}

export interface PageModelResponse {
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
	render_mode?: string;
	content_selector_used?: string;
	onpage?: OnPageScore | null;
}

export interface SeoFields {
	focus_keyphrase?: string;
	related_keyphrases?: Array< { keyphrase: string; synonyms?: string[] } >;
	synonyms?: string[];
	title?: string;
	description?: string;
	canonical?: string;
	robots?: { index?: boolean | null; follow?: boolean | null };
	og_title?: string;
	og_description?: string;
	resolved_title?: string;
	resolved_description?: string;
}

export interface PageListItem {
	page_id: number;
	object_id: number;
	title: string;
	url: string;
	edit_url: string;
	keyphrase: string;
	onpage: { score: number | null; denominator: number | null };
	seo_rating: string | null;
	readability_rating: string | null;
	top_issue: string;
	last_rendered_at: string | null;
	last_error: string | null;
}

export interface PageList {
	items: PageListItem[];
	total: number;
	pages: number;
	summary?: {
		analysed: number;
		average: number | null;
		red: number;
		orange: number;
		green: number;
	};
}

export interface PageRecord {
	page_id: number;
	page_model: PageModelResponse;
	scores: Array< {
		keyphrase: string;
		engine: string;
		score: number;
		denominator: number;
		rating: string;
		details: OnPageScore | { assessments?: ListedAssessment[] };
	} >;
	render_mode?: string;
	content_selector?: string;
	last_error?: string | null;
}

export interface ContentScoreBody {
	keyphrase: string;
	seo: { score: number; rating: string; assessments: ListedAssessment[] };
	readability: {
		score: number;
		rating: string;
		assessments: ListedAssessment[];
	};
}

export function fetchPages(
	query: Record< string, string >
): Promise< PageList > {
	const params = new URLSearchParams( query );
	return apiFetch( { path: `/seostudio/v1/pages?${ params.toString() }` } );
}

export function fetchSeo( postId: number ): Promise< SeoFields > {
	return apiFetch( { path: `/seostudio/v1/posts/${ postId }/seo` } );
}

export function saveSeo(
	postId: number,
	body: SeoFields
): Promise< SeoFields > {
	return apiFetch( {
		path: `/seostudio/v1/posts/${ postId }/seo`,
		method: 'PUT',
		data: body,
	} );
}

export function analysePost(
	postId: number,
	body: { mode?: string; keyphrase?: string }
): Promise< PageRecord > {
	return apiFetch( {
		path: `/seostudio/v1/analyse/post/${ postId }`,
		method: 'POST',
		data: body,
	} );
}

export function previewModel(
	postId: number,
	body: {
		content_html: string;
		title?: string;
		excerpt?: string;
		seo_title: string;
		seo_description: string;
		keyphrase: string;
	}
): Promise< PageModelResponse > {
	return apiFetch( {
		path: `/seostudio/v1/posts/${ postId }/model`,
		method: 'POST',
		data: body,
	} );
}

export function saveContentScores(
	pageId: number,
	body: ContentScoreBody
): Promise< unknown > {
	return apiFetch( {
		path: `/seostudio/v1/pages/${ pageId }/content-scores`,
		method: 'POST',
		data: body,
	} );
}

export function keyphraseUsage(
	keyphrase: string
): Promise< { posts: Array< { post_id: number } > } > {
	const params = new URLSearchParams( { keyphrase } );
	return apiFetch( {
		path: `/seostudio/v1/keyphrases/usage?${ params.toString() }`,
	} );
}

export function startAudit(): Promise< { message?: string; code?: string } > {
	return apiFetch( {
		path: '/seostudio/v1/audits',
		method: 'POST',
		data: { type: 'audit' },
	} );
}
