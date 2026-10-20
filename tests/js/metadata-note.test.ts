import { metadataNote } from '../../assets/src/editor/metadata-note';

describe( 'metadata ownership guidance', () => {
	it( 'sends an administrator to homepage settings', () => {
		const note = metadataNote( {
			publishesOwn: true,
			activePlugin: '',
			isHomepage: true,
			canManage: true,
		} );
		expect( note ).toContain( 'Zoop settings' );
		expect( note ).toContain( 'does not publish a separate SEO title' );
	} );

	it( 'names the plugin that prints the live tags', () => {
		const note = metadataNote( {
			publishesOwn: false,
			activePlugin: 'Yoast SEO',
		} );
		expect( note ).toContain( 'Yoast SEO' );
		expect( note ).toContain( 'will not write a second set of tags' );
	} );

	it( 'does not offer a publishing control in analysis-only mode', () => {
		expect(
			metadataNote( {
				publishesOwn: false,
				activePlugin: '',
				isHomepage: true,
			} )
		).toContain( 'not printing tags' );
	} );
} );
