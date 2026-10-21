import { renderToStaticMarkup } from 'react-dom/server';
import {
	CoverageActions,
	UnverifiedLinks,
} from '../../assets/src/dashboard/architecture-sections';

const emptyReasons = {
	unanalysed: 0,
	content_only: 0,
	stale_links: 0,
	navigation_limited: false,
	navigation_note: '',
};

describe( 'architecture evidence actions', () => {
	it( 'offers a content audit only when pages have not been analysed', () => {
		const hidden = renderToStaticMarkup(
			<CoverageActions
				reasons={ { ...emptyReasons, content_only: 26 } }
				needsPublishedCheck={ 5 }
				onAudit={ () => undefined }
			/>
		);
		expect( hidden ).not.toContain( 'Run site audit' );
		expect( hidden ).toContain( '5 pages need a published-page check' );
		expect( hidden ).not.toContain(
			'26 pages need a published-page check'
		);
		expect( hidden ).toContain( 'score_state=needs_published_check' );
		expect( hidden ).not.toContain( 'score_state=unchecked' );
		expect( hidden ).not.toContain( "haven't been analysed" );

		const absent = renderToStaticMarkup(
			<CoverageActions
				reasons={ { ...emptyReasons, content_only: 4, stale_links: 1 } }
				needsPublishedCheck={ null }
				onAudit={ () => undefined }
			/>
		);
		expect( absent ).toBe( '' );

		const audit = renderToStaticMarkup(
			<CoverageActions
				reasons={ { ...emptyReasons, unanalysed: 2 } }
				needsPublishedCheck={ 2 }
				onAudit={ () => undefined }
			/>
		);
		expect( audit ).toContain( 'Run site audit' );
		expect( audit ).toContain(
			'This checks page content. To count orphans, Zoop also needs to check the published pages.'
		);
		expect( audit ).toContain( '2 pages need a published-page check' );
	} );

	it( 'hides the unverified heading until a row exists', () => {
		expect( renderToStaticMarkup( <UnverifiedLinks links={ [] } /> ) ).toBe(
			''
		);
		const html = renderToStaticMarkup(
			<UnverifiedLinks
				links={ [
					{
						from_id: 'post:1',
						from_title: 'Home',
						href: 'https://example.test/unknown',
						kind: 'contextual',
						anchor: 'Unknown',
						occurrences: 1,
					},
				] }
			/>
		);
		expect( html.replace( /&#x27;/g, "'" ) ).toContain(
			"Links Zoop couldn't verify"
		);
		expect( html ).toContain( 'https://example.test/unknown' );
	} );
} );
