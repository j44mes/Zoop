import { scorePaper } from '../../assets/src/analysis/score-paper';

describe( 'scorePaper', () => {
	it( 'scores a fixture paper with the content-analysis package', () => {
		const result = scorePaper( {
			html: '<p>The rank tracker guide explains how a rank tracker works for teams that need a rank tracker.</p><h2>How a rank tracker works</h2><p>A rank tracker checks positions every morning. This rank tracker example gives the team a rank tracker they can trust.</p>',
			keyphrase: 'rank tracker',
			title: 'Rank tracker guide',
			description:
				'A rank tracker guide for teams that need a rank tracker.',
			slug: 'rank-tracker-guide',
			permalink: 'https://example.test/rank-tracker-guide',
			synonyms: [ 'ranking tool' ],
			usedElsewhere: [],
		} );

		expect( result.seo.score ).toBeGreaterThanOrEqual( 0 );
		expect( result.seo.score ).toBeLessThanOrEqual( 100 );
		expect( result.seo.assessments.map( ( row ) => row.id ) ).toContain(
			'introductionKeyword'
		);
		expect( result.readability.score ).toBeGreaterThan( 0 );
		expect( [ 'red', 'orange', 'green', 'grey' ] ).toContain(
			result.seo.rating
		);
		expect(
			result.seo.assessments.some(
				( row ) =>
					row.id === 'previouslyUsedKeywords' && row.score === 9
			)
		).toBe( true );
	} );

	it( 'marks a keyphrase that is already used on another post', () => {
		const result = scorePaper( {
			html: '<p>Rank tracker details.</p>',
			keyphrase: 'rank tracker',
			usedElsewhere: [ 42, 43 ],
		} );
		const row = result.seo.assessments.find(
			( item ) => item.id === 'previouslyUsedKeywords'
		);
		expect( row?.score ).toBe( 1 );
		expect( row?.text ).not.toMatch( /yoa\.st|yoast/i );
	} );
} );
