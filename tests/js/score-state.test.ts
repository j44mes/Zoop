import {
	historicalScoreLabel,
	scoreStateLabel,
	suggestionForState,
} from '../../assets/src/dashboard/score-state';

describe( 'score freshness labels', () => {
	it( 'keeps a stale result out of the current score and the next step', () => {
		expect( scoreStateLabel( 'needs_reanalysis', null, null ) ).toBe(
			'Out of date'
		);
		expect( scoreStateLabel( 'unscored', null, null ) ).toBe(
			'Not scored'
		);
		expect( scoreStateLabel( 'not_analysed', null, null ) ).toBe(
			'Not analysed yet'
		);
		expect( scoreStateLabel( 'current', 40, 80 ) ).toBe( '40 / 80' );
		expect(
			suggestionForState( 'needs_reanalysis', 'title.exists', 'Title' )
		).toBe( 'Out of date.' );
		expect( suggestionForState( 'current', 'title.exists', 'Title' ) ).toBe(
			'Title'
		);
		expect( historicalScoreLabel( 35, 100 ) ).toBe(
			'Previous result: 35 / 100. Out of date.'
		);
	} );
} );
