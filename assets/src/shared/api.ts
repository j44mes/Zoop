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
	images?: Array< {
		src: string;
		alt: string;
		in_body?: boolean;
		kind?: string;
		source?: string;
	} >;
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
	proposal_revision?: number;
	canonical?: string;
	robots?: { index?: boolean | null; follow?: boolean | null };
	og_title?: string;
	og_description?: string;
	video_url?: string;
	is_news?: boolean | string | number;
	resolved_title?: string;
	resolved_description?: string;
}

export interface ScorePresentation {
	definitive: boolean;
	historical: boolean;
	headline: string;
	explanation: string;
	checks_evaluated: number;
	checks_applicable: number;
	missing: Array< {
		id: string;
		label: string;
		action: string;
		action_label: string;
	} >;
	verified_label: string;
	search_status: string;
	search_label: string;
	search_explanation: string;
	content_rating?: string;
	canonical_note?: string;
	checks?: Array< {
		id: string;
		state: string;
		label: string;
		reason: string;
		action: string;
		action_label: string;
		category?: string;
		measured?: string;
		recommendation?: string;
	} >;
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
	score_state?: 'current' | 'needs_reanalysis' | 'unscored' | 'not_analysed';
	presentation?: ScorePresentation;
	last_rendered_at: string | null;
	last_fetched_at?: string | null;
	verified_at?: string | null;
	last_error: string | null;
	fetch_http_status?: number | null;
	fetch_failure_reason?: string;
	fetch_attempted_at?: string | null;
	retained_notice?: string;
	fetch_guidance_url?: string;
}

export interface PageList {
	items: PageListItem[];
	total: number;
	pages: number;
	summary?: {
		analysed: number;
		scored?: number;
		unscored?: number;
		needs_reanalysis?: number;
		not_analysed?: number;
		failed?: number;
		average: number | null;
		red: number;
		orange: number;
		green: number;
		reanalysis_notice?: string;
		search_discouraged?: boolean;
		suggestion_state?: 'unscored' | 'incomplete' | 'clear' | 'items';
		suggestion_notice?: string;
		checked?: number;
		unchecked?: number;
	};
}

export interface PageRecord {
	page_id: number;
	url?: string;
	page_model: PageModelResponse;
	scores: Array< {
		keyphrase: string;
		engine: string;
		score: number;
		denominator: number;
		rating: string;
		current?: boolean;
		evidence_version?: number;
		details: OnPageScore | { assessments?: ListedAssessment[] };
	} >;
	render_mode?: string;
	content_selector?: string;
	last_error?: string | null;
	fetch_http_status?: number | null;
	fetch_failure_reason?: string;
	fetch_attempted_at?: string | null;
	last_fetched_at?: string | null;
	verified_at?: string | null;
	retained_notice?: string;
	fetch_guidance_url?: string;
	presentation?: ScorePresentation;
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

export interface AppearanceText {
	title: string;
	description: string;
}

export interface AppearanceVerification {
	status: string;
	html_matches: boolean;
	basis: string;
	note: string;
	observed_title: string;
	observed_description: string;
	checked_at: string;
}

export interface AppearanceBaseline extends AppearanceText {
	target: string;
}

export interface AppearanceRecord {
	publishing: boolean;
	can_apply: boolean;
	can_restore: boolean;
	controller: string;
	edit_url: string;
	url: string;
	resolved: AppearanceText & { basis: string };
	published: AppearanceText;
	baseline: AppearanceBaseline;
	proposal_saved: boolean;
	proposal_revision: number;
	proposal: AppearanceText;
	previous: AppearanceText;
	verification: AppearanceVerification | null;
}

export function fetchAppearance( postId: number ): Promise< AppearanceRecord > {
	return apiFetch( {
		path: `/seostudio/v1/posts/${ postId }/appearance`,
	} );
}

export function saveAppearance(
	postId: number,
	body: AppearanceText & { proposal_revision: number }
): Promise< AppearanceRecord > {
	return apiFetch( {
		path: `/seostudio/v1/posts/${ postId }/appearance`,
		method: 'PUT',
		data: body,
	} );
}

export function applyAppearance(
	postId: number,
	body: {
		confirm: boolean;
		proposal_revision: number;
		baseline_title: string;
		baseline_description: string;
	}
): Promise< AppearanceRecord > {
	return apiFetch( {
		path: `/seostudio/v1/posts/${ postId }/appearance/apply`,
		method: 'POST',
		data: body,
	} );
}

export function restoreAppearance(
	postId: number,
	baseline: AppearanceText
): Promise< AppearanceRecord > {
	return apiFetch( {
		path: `/seostudio/v1/posts/${ postId }/appearance/restore`,
		method: 'POST',
		data: {
			confirm: true,
			baseline_title: baseline.title,
			baseline_description: baseline.description,
		},
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

export interface AuditRun {
	run_id: number;
	status?: string;
	processed?: number;
	total?: number;
	failed?: number;
	message?: string;
	code?: string;
}

export interface StudioSettings {
	mode: 'analysis' | 'owner';
	site_title_separator: string;
	home_title: string;
	home_description: string;
	batch_size: number;
	batch_pause_ms: number;
	uninstall_delete_data: boolean;
	publishing?: boolean;
	detected?: { name?: string } | null;
	version_label?: string;
	function_word_locale?: string;
	og_default_image?: number;
	default_robots?: {
		index?: boolean;
		follow?: boolean;
		max_snippet?: number | null;
		max_image_preview?: string | null;
	};
	content_selectors?: Record< string, string >;
	local_business: {
		enabled: boolean;
		name: string;
		street: string;
		city: string;
		telephone: string;
	};
}

export async function fetchPostIdentity(
	postId: number
): Promise< { title: string; link: string } > {
	const types = [ 'posts', 'pages' ];
	for ( const type of types ) {
		try {
			const post = await apiFetch< {
				title?: { raw?: string; rendered?: string };
				link?: string;
			} >( { path: `/wp/v2/${ type }/${ postId }?context=edit` } );
			return {
				title: post.title?.raw || post.title?.rendered || '',
				link: post.link || '',
			};
		} catch ( _error ) {
			// The id belongs to one public type. Try the other.
		}
	}
	return { title: '', link: '' };
}

export function fetchPage( pageId: number ): Promise< PageRecord > {
	return apiFetch( { path: `/seostudio/v1/pages/${ pageId }` } );
}

export function fetchAudit( runId: number ): Promise< AuditRun > {
	return apiFetch( { path: `/seostudio/v1/audits/${ runId }` } );
}

export function cancelAudit( runId: number ): Promise< unknown > {
	return apiFetch( {
		path: `/seostudio/v1/audits/${ runId }`,
		method: 'DELETE',
	} );
}

export function startAudit(): Promise< AuditRun > {
	return apiFetch( {
		path: '/seostudio/v1/audits',
		method: 'POST',
		data: { type: 'audit' },
	} );
}

export interface UpgradeNoticePayload {
	ruleset?: number;
	count: number;
	message: string;
	review_url: string;
}

export interface CapabilityManifest {
	manage: boolean;
	edit_posts: boolean;
	publishing: boolean;
	detected_name: string;
	version_label?: string;
	upgrade_notice?: UpgradeNoticePayload | null;
	legacy_notice?: UpgradeNoticePayload | null;
}

export function fetchCapabilities(): Promise< CapabilityManifest > {
	return apiFetch( { path: '/seostudio/v1/capabilities' } );
}

export function dismissRulesetNotice(): Promise< { dismissed: boolean } > {
	return apiFetch( {
		path: '/seostudio/v1/notices/ruleset-retirement/dismiss',
		method: 'POST',
	} );
}

export function dismissLegacyScoreNotice(): Promise< { dismissed: boolean } > {
	return apiFetch( {
		path: '/seostudio/v1/notices/legacy-scores/dismiss',
		method: 'POST',
	} );
}

export function fetchSettings(): Promise< StudioSettings > {
	return apiFetch( { path: '/seostudio/v1/settings' } );
}

export function saveSettings(
	body: Partial< StudioSettings >
): Promise< StudioSettings > {
	return apiFetch( {
		path: '/seostudio/v1/settings',
		method: 'PUT',
		data: body,
	} );
}

export function previewMigration( source: string ): Promise< {
	source: string;
	counts: Record< string, number >;
} > {
	return apiFetch( {
		path: '/seostudio/v1/migrations/preview',
		method: 'POST',
		data: { source },
	} );
}

export function startMigration(
	source: string
): Promise< { run_id: number } > {
	return apiFetch( {
		path: '/seostudio/v1/migrations',
		method: 'POST',
		data: { source },
	} );
}

export interface ArchitectureCoverageReasons {
	unanalysed: number;
	content_only: number;
	stale_links: number;
	navigation_limited: boolean;
	navigation_note: string;
}

export interface ArchitecturePage {
	id: string;
	post_id: number;
	title: string;
	url: string;
	analysed: boolean;
	depth: number | null;
	referring_pages: number;
	contextual_referrers: number;
	template_referrers: number;
	contextual_occurrences: number;
	template_occurrences: number;
	status: string;
	observation?: string;
	evidence?: string;
	confidence?: string;
	action?: string;
	verify?: string;
	candidates?: Array< {
		post_id: number;
		title: string;
		url: string;
		reason?: string;
	} >;
}

export interface ArchitectureReport {
	coverage: {
		complete: boolean;
		inventory: number;
		analysed: number;
		missing: number;
		needs_published_check?: number;
		withheld: boolean;
		reasons?: ArchitectureCoverageReasons;
	};
	counts: {
		inventory: number;
		analysed: number;
		orphans: number | null;
		weak: number;
		template_only: number;
		unknown: number;
		unresolved: number;
		unreachable: number | null;
		needs_published_check?: number;
		navigation: number;
		unverified?: number;
	};
	depth_explanation?: string;
	orphan_explanation?: string;
	deepest: ArchitecturePage | null;
	orphans: ArchitecturePage[];
	weak: ArchitecturePage[];
	template_only: ArchitecturePage[];
	unresolved: Array< {
		from_id: string;
		from_title: string;
		href: string;
		kind: string;
		anchor: string;
		occurrences: number;
		resolution?: string;
		reason?: string;
	} >;
	unverified?: Array< {
		from_id: string;
		from_title: string;
		href: string;
		kind: string;
		anchor: string;
		occurrences: number;
		reason?: string;
	} >;
	possible_aliases?: Array< {
		host: string;
		count: number;
		pages: number;
		message: string;
	} >;
	trusted_aliases?: string[];
	limited: boolean;
	notes: string[];
}

export function fetchArchitecture(): Promise< ArchitectureReport > {
	return apiFetch( { path: '/seostudio/v1/architecture' } );
}

export function trustAlias(
	host: string,
	trust: boolean
): Promise< ArchitectureReport > {
	return apiFetch( {
		path: '/seostudio/v1/architecture/aliases',
		method: 'POST',
		data: { host, trust },
	} );
}

export interface TopicField {
	value: string | string[];
	reason: string;
}

export interface TopicRecord {
	post_id: number;
	note: string;
	suggestions: {
		primary_topic: TopicField;
		page_role: TopicField;
		search_intent: TopicField;
		parent_topic: TopicField;
		supporting_entities: TopicField;
	};
	effective: {
		primary_topic: string;
		page_role: string;
		search_intent: string;
		parent_topic: string;
		supporting_entities: string[];
	};
}

export function fetchTopic( postId: number ): Promise< TopicRecord > {
	return apiFetch( { path: `/seostudio/v1/posts/${ postId }/topic` } );
}

export interface OverlapEvidencePage {
	post_id: number;
	title: string;
	source: string;
	confirmed?: boolean;
}

export interface OverlapEvidence {
	signal: string;
	source: string;
	observation_confidence: string;
	establishes: string;
	independent: boolean;
	detail: string;
	pages?: OverlapEvidencePage[];
}

export interface OverlapGroup {
	level: string;
	confidence: string;
	observation: string;
	observation_confidence: string;
	conclusion: string;
	conclusion_confidence: string;
	phrase: string;
	reason: string;
	evidence: OverlapEvidence[];
	pages: Array< { post_id: number; title: string } >;
}

export interface OverlapReport {
	groups: OverlapGroup[];
	notes: string[];
}

export function fetchOverlap(): Promise< OverlapReport > {
	return apiFetch( { path: '/seostudio/v1/overlap' } );
}

export interface SearchRow {
	query: string;
	page: string;
	clicks: number;
	impressions: number;
	position: number | null;
}

export interface SearchSummary {
	connected: boolean;
	rows: number;
	rows_with_page: number;
	rows_without_page: number;
	skipped: number;
	imported_at: string;
	top: SearchRow[];
	note: string;
}

export function fetchSearchConsole(): Promise< SearchSummary > {
	return apiFetch( { path: '/seostudio/v1/search-console' } );
}

export function importSearchConsole( csv: string ): Promise< SearchSummary > {
	return apiFetch( {
		path: '/seostudio/v1/search-console',
		method: 'POST',
		data: { csv },
	} );
}

export interface RecommendationPage {
	post_id: number;
	title: string;
	url: string;
	redacted?: boolean;
}

export interface RecommendationItem {
	priority: number;
	kind: string;
	title: string;
	action: string;
	reason: string;
	observation?: string;
	observation_confidence?: string;
	conclusion?: string;
	conclusion_confidence?: string;
	evidence?: string;
	confidence?: string;
	verify?: string;
	candidates?: Array< {
		post_id: number;
		title: string;
		url: string;
		reason?: string;
	} >;
	pages: RecommendationPage[];
	href?: string;
}

export interface RecommendationReport {
	items: RecommendationItem[];
	notes: string[];
	limited: boolean;
}

export function fetchRecommendations(): Promise< RecommendationReport > {
	return apiFetch( { path: '/seostudio/v1/recommendations' } );
}

export function saveTopic(
	postId: number,
	body: {
		primary_topic: string;
		page_role: string;
		search_intent: string;
		parent_topic: string;
		supporting_entities: string[];
	}
): Promise< TopicRecord > {
	return apiFetch( {
		path: `/seostudio/v1/posts/${ postId }/topic`,
		method: 'PUT',
		data: body,
	} );
}
