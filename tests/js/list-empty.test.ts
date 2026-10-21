import {
	dashboardFirstRun,
	isFirstRun,
	pagesListEmpty,
	suggestionMessage,
} from '../../assets/src/dashboard/list-empty';

describe( 'overview and pages empty states', () => {
	it( 'uses one first-run decision and keeps filtered results separate', () => {
		expect( isFirstRun( 0, false ) ).toBe( true );
		expect( isFirstRun( 0, true ) ).toBe( false );
		expect( isFirstRun( 3, false ) ).toBe( false );
		expect( dashboardFirstRun( undefined, false ) ).toBe( false );
		expect( dashboardFirstRun( null, false ) ).toBe( false );
		expect( dashboardFirstRun( 0, false ) ).toBe( true );
		expect( dashboardFirstRun( 0, true ) ).toBe( false );
		expect( pagesListEmpty( false ) ).toBe(
			'No pages are stored yet. Open a page and choose Analyse page.'
		);
		expect( pagesListEmpty( true ) ).toBe(
			'No stored pages match these filters.'
		);
	} );

	it( 'does not treat an empty suggestion list as all-clear', () => {
		expect( suggestionMessage( 'unscored' ) ).toBe(
			'No suggestions yet. Check a page to get some.'
		);
		expect( suggestionMessage( 'clear' ) ).toBe(
			'Nothing to fix right now.'
		);
		expect( suggestionMessage( 'incomplete' ) ).toBeNull();
		expect(
			suggestionMessage(
				'incomplete',
				"No fixes found on the 3 checked pages. 23 pages haven't been checked yet."
			)
		).toBe(
			"No fixes found on the 3 checked pages. 23 pages haven't been checked yet."
		);
		expect( suggestionMessage( 'error' ) ).toBe(
			'Suggestions could not be loaded.'
		);
		expect( suggestionMessage( 'items' ) ).toBeNull();
		expect( suggestionMessage( 'loading' ) ).toBeNull();
	} );
} );
