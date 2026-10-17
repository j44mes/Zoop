declare module 'yoastseo' {
	export class Paper {
		constructor( text: string, attributes?: Record< string, unknown > );
		getKeyword(): string;
	}
	export class SeoAssessor {
		constructor( researcher: unknown );
		assess( paper: Paper ): void;
		getValidResults(): YoastResult[];
		calculateOverallScore(): number;
	}
	export class ContentAssessor {
		constructor( researcher: unknown );
		assess( paper: Paper ): void;
		getValidResults(): YoastResult[];
		calculateOverallScore(): number;
	}
}

declare module 'yoastseo/build/languageProcessing/languages/en/Researcher' {
	const Researcher: new ( paper?: unknown ) => unknown;
	export default Researcher;
}

declare module 'yoastseo/build/scoring/assessors/inclusiveLanguageAssessor' {
	const Assessor: new ( researcher: unknown ) => {
		assess( paper: unknown ): void;
		getValidResults(): YoastResult[];
	};
	export default Assessor;
}

declare module 'yoastseo/build/scoring/assessors/relatedKeywordAssessor' {
	const Assessor: new ( researcher: unknown ) => {
		assess( paper: unknown ): void;
		getValidResults(): YoastResult[];
		calculateOverallScore(): number;
	};
	export default Assessor;
}

declare module 'yoastseo/build/bundledPlugins/previouslyUsedKeywords' {
	const Plugin: new (
		app: unknown,
		args?: {
			usedKeywords?: Record< string, number[] >;
			usedKeywordsPostTypes?: Record< string, string[] >;
			searchUrl?: string;
			postUrl?: string;
		}
	) => {
		researchPreviouslyUsedKeywords( paper: unknown ): { count?: number };
		scoreAssessment(
			research: { count?: number },
			paper: unknown
		): { text: string; score: number };
	};
	export default Plugin;
}

interface YoastResult {
	getIdentifier(): string;
	getScore(): number;
	getText(): string;
}
