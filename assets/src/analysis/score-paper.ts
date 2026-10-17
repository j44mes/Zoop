import { ContentAssessor, Paper, SeoAssessor } from 'yoastseo';
import EnglishResearcher from 'yoastseo/build/languageProcessing/languages/en/Researcher';
import InclusiveLanguageAssessor from 'yoastseo/build/scoring/assessors/inclusiveLanguageAssessor';
import RelatedKeywordAssessor from 'yoastseo/build/scoring/assessors/relatedKeywordAssessor';
import PreviouslyUsedKeywords from 'yoastseo/build/bundledPlugins/previouslyUsedKeywords';

export type TrafficRating = 'red' | 'orange' | 'green' | 'grey';

export interface PaperInput {
	html: string;
	keyphrase: string;
	relatedKeyphrases?: string[];
	synonyms?: string[];
	title?: string;
	description?: string;
	slug?: string;
	permalink?: string;
	locale?: string;
	usedElsewhere?: number[];
}

export interface AnalysisRow {
	id: string;
	score: number;
	rating: TrafficRating;
	text: string;
}

export interface EngineResult {
	score: number;
	rating: TrafficRating;
	assessments: AnalysisRow[];
}

export interface ContentAnalysis {
	seo: EngineResult;
	readability: EngineResult;
	inclusive: AnalysisRow[];
	related: Array< EngineResult & { keyphrase: string } >;
}

interface YoastResult {
	getIdentifier: () => string;
	getScore: () => number;
	getText: () => string;
}

function constructorOf< T >( module: T ): T {
	if (
		module &&
		typeof module === 'object' &&
		'default' in module &&
		typeof ( module as { default: unknown } ).default === 'function'
	) {
		return ( module as { default: T } ).default;
	}
	return module;
}

function plain( value: string ): string {
	return value
		.replace( /<[^>]+>/g, '' )
		.replace( /\s+/g, ' ' )
		.trim();
}

export function trafficLight( score: number ): TrafficRating {
	if ( score < 41 ) {
		return 'red';
	}
	if ( score <= 70 ) {
		return 'orange';
	}
	return 'green';
}

function rowRating( score: number ): TrafficRating {
	if ( score <= 4 ) {
		return 'red';
	}
	if ( score <= 7 ) {
		return 'orange';
	}
	if ( score > 7 ) {
		return 'green';
	}
	return 'grey';
}

function rows( results: YoastResult[] ): AnalysisRow[] {
	return results.map( ( result ) => ( {
		id: result.getIdentifier(),
		score: result.getScore(),
		rating: rowRating( result.getScore() ),
		text: plain( result.getText() ),
	} ) );
}

function engine(
	assess: ( paper: Paper ) => void,
	results: () => YoastResult[],
	overall: () => number,
	paper: Paper
): EngineResult {
	assess( paper );
	const score = overall();
	return {
		score,
		rating: trafficLight( score ),
		assessments: rows( results() ),
	};
}

/**
 * Run the published content-analysis package on one paper.
 * @param input
 */
export function scorePaper( input: PaperInput ): ContentAnalysis {
	const Researcher = constructorOf( EnglishResearcher );
	const researcher = new Researcher();
	const paper = new Paper( input.html, {
		keyword: input.keyphrase,
		synonyms: ( input.synonyms ?? [] ).join( ',' ),
		title: input.title ?? '',
		description: input.description ?? '',
		slug: input.slug ?? '',
		permalink: input.permalink ?? '',
		locale: input.locale || 'en_US',
	} );

	const seoAssessor = new SeoAssessor( researcher );
	const contentAssessor = new ContentAssessor( researcher );
	const seo = engine(
		( next ) => seoAssessor.assess( next ),
		() => seoAssessor.getValidResults(),
		() => seoAssessor.calculateOverallScore(),
		paper
	);
	const readability = engine(
		( next ) => contentAssessor.assess( next ),
		() => contentAssessor.getValidResults(),
		() => contentAssessor.calculateOverallScore(),
		paper
	);

	const Inclusive = constructorOf( InclusiveLanguageAssessor );
	const inclusiveAssessor = new Inclusive( researcher );
	inclusiveAssessor.assess( paper );

	const used = new PreviouslyUsedKeywords(
		{ registerAssessment() {} },
		{
			usedKeywords: {
				[ input.keyphrase ]: input.usedElsewhere ?? [],
			},
			usedKeywordsPostTypes: {},
			searchUrl: '',
			postUrl: '',
		}
	);
	const researched = used.researchPreviouslyUsedKeywords( paper );
	const previous = used.scoreAssessment( researched, paper );
	seo.assessments.push( {
		id: 'previouslyUsedKeywords',
		score: previous.score,
		rating: rowRating( previous.score ),
		text: plain( previous.text ),
	} );

	const Related = constructorOf( RelatedKeywordAssessor );
	const related = ( input.relatedKeyphrases ?? [] )
		.filter( ( keyphrase ) => keyphrase.trim() !== '' )
		.map( ( keyphrase ) => {
			const relatedPaper = new Paper( input.html, {
				keyword: keyphrase,
				title: input.title ?? '',
				description: input.description ?? '',
				slug: input.slug ?? '',
				locale: input.locale || 'en_US',
			} );
			const assessor = new Related( researcher );
			const result = engine(
				( next ) => assessor.assess( next ),
				() => assessor.getValidResults(),
				() => assessor.calculateOverallScore(),
				relatedPaper
			);
			return { keyphrase, ...result };
		} );

	return {
		seo,
		readability,
		inclusive: rows( inclusiveAssessor.getValidResults() ),
		related,
	};
}
