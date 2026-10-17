import { metadataNote } from '../../assets/src/editor/metadata-note';

describe( 'metadata ownership guidance', () => {
	it( 'identifies homepage settings and the page fields they override', () => {
		const note = metadataNote( {
			publishesOwn: true,
			activePlugin: '',
			isHomepage: true,
		} );
		expect( note ).toContain(
			'Home title and Home description in Zoop Settings'
		);
		expect( note ).toContain( 'saved but do not override' );
		expect( note ).toContain( 'Social overrides still apply' );
	} );

	it( 'keeps analysis-only guidance when the static homepage is unpublished', () => {
		expect(
			metadataNote( {
				publishesOwn: false,
				activePlugin: '',
				isHomepage: true,
			} )
		).toContain( 'Analysis-only mode' );
	} );
} );
