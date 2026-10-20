import {
	pagesListEmpty,
	suggestionEmpty,
} from '../../assets/src/dashboard/list-empty';

describe( 'overview and pages empty states', () => {
	it( 'keeps an empty site distinct from stored pages that have no score', () => {
		expect( suggestionEmpty( 0, 0 ) ).toBe(
			'No stored pages yet. Open a page and choose Analyse page.'
		);
		expect( suggestionEmpty( 4, 0 ) ).toBe(
			'No pages have a score yet. Open a page and choose Check published page.'
		);
		expect( suggestionEmpty( 4, 2 ) ).toBe(
			'No suggested pages were stored.'
		);
	} );

	it( 'keeps a filtered list distinct from an empty site', () => {
		expect( pagesListEmpty( false ) ).toBe(
			'No pages are stored yet. Open a page and choose Analyse page.'
		);
		expect( pagesListEmpty( true ) ).toBe(
			'No stored pages match these filters.'
		);
	} );
} );
