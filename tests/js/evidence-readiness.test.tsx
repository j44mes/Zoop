import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { renderToStaticMarkup } from 'react-dom/server';
import { EvidenceReadiness } from '../../assets/src/shared/ui/EvidenceReadiness';
import type { ScorePresentation } from '../../assets/src/shared/api';

const presentation: ScorePresentation = {
	definitive: false,
	historical: true,
	headline: 'Out of date',
	explanation: '',
	checks_evaluated: 4,
	checks_applicable: 6,
	missing: [
		{
			id: 'keyphrase',
			label: 'The keyphrase has not been checked in an observed title.',
			action: 'add_keyphrase',
			action_label: 'Add focus keyphrase',
		},
	],
	verified_label: '',
	search_status: 'noindex',
	search_label: 'Hidden from search',
	search_explanation: 'The choice may be intentional.',
	checks: [
		{
			id: 'title.exists',
			state: 'passed',
			label: 'Passed',
			reason: 'A title tag is present.',
			action: 'view_evidence',
			action_label: 'View evidence',
			category: 'title',
			measured: 'Rank tracker',
			recommendation: 'No change needed.',
		},
		{
			id: 'title.length',
			state: 'needs_work',
			label: 'Needs work',
			reason: 'The title is short.',
			action: 'view_fix',
			action_label: 'View safe fix',
			category: 'title',
			measured: '12',
			recommendation:
				'Rewrite the title so it is between 30 and 60 characters.',
		},
		{
			id: 'code.stylesheet',
			state: 'not_checked',
			label: 'Not checked',
			reason: 'A full-page fetch is required.',
			action: 'check_published',
			action_label: 'Check published page',
			measured: '',
			recommendation: '',
		},
		{
			id: 'url.https',
			state: 'out_of_date',
			label: 'Out of date',
			reason: 'Old.',
			action: 'check_published',
			action_label: 'Check published page',
			measured: 'https://example.test',
			recommendation: '',
		},
		{
			id: 'meta.keyphrase',
			state: 'not_applicable',
			label: "Doesn't apply",
			reason: 'The tag is not in the page.',
			action: 'view_explanation',
			action_label: 'View explanation',
			measured: '',
			recommendation: '',
		},
	],
};

describe( 'evidence readiness UI', () => {
	it( 'groups stored checks and keeps a noindex status separate from the score', () => {
		const html = renderToStaticMarkup(
			<EvidenceReadiness
				presentation={ presentation }
				editorUrl="post.php?post=4&action=edit"
				onCheckPublished={ () => undefined }
				onAddKeyphrase={ () => undefined }
			>
				<p>Out of date</p>
			</EvidenceReadiness>
		);
		expect( html ).toContain( 'seostudio-search-hidden' );
		expect( html ).toContain( 'Hidden from search' );
		expect( html ).toContain( 'Missing evidence' );
		expect( html ).toContain( 'Add focus keyphrase' );
		expect( html.indexOf( 'aria-label="Needs work"' ) ).toBeLessThan(
			html.indexOf( 'aria-label="Not checked"' )
		);
		expect( html.indexOf( 'aria-label="Not checked"' ) ).toBeLessThan(
			html.indexOf( 'aria-label="Out of date"' )
		);
		expect( html.indexOf( 'aria-label="Out of date"' ) ).toBeLessThan(
			html.indexOf( 'aria-controls="seostudio-passed-checks"' )
		);
		expect( html ).toContain( 'aria-expanded="false"' );
		expect( html ).toContain( 'id="seostudio-passed-checks" hidden=""' );
		expect( html ).not.toContain( 'No measured value was stored.' );
		expect( html ).toContain( 'Show all checks' );
		expect( html ).toContain( 'Doesn&#x27;t apply' );
	} );

	it( 'does not paint not-public or site-discouraged pages as noindex', () => {
		const html = renderToStaticMarkup(
			<EvidenceReadiness
				presentation={ {
					...presentation,
					search_status: 'not_public',
					search_label: 'This page is not public',
					checks: [],
					missing: [],
				} }
				onCheckPublished={ () => undefined }
				onAddKeyphrase={ () => undefined }
			/>
		);
		expect( html ).toContain( 'This page is not public' );
		expect( html ).not.toContain( 'seostudio-search-hidden' );
		expect( html ).not.toContain( 'Hidden from search' );
	} );

	it( 'runs the stored recovery and reveal actions', () => {
		(
			globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
		 ).IS_REACT_ACT_ENVIRONMENT = true;
		const published: string[] = [];
		const phrases: string[] = [];
		const container = document.createElement( 'div' );
		document.body.appendChild( container );
		const root = createRoot( container );
		act( () => {
			root.render(
				<EvidenceReadiness
					presentation={ presentation }
					editorUrl="post.php?post=4&action=edit"
					onCheckPublished={ () => published.push( 'live' ) }
					onAddKeyphrase={ () => phrases.push( 'focus' ) }
				/>
			);
		} );
		const button = ( name: string ) =>
			Array.from( container.querySelectorAll( 'button' ) ).find(
				( node ) => node.textContent?.includes( name )
			);
		const passedToggle = button( 'Passed' );
		expect( passedToggle?.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect(
			container
				.querySelector( '#seostudio-passed-checks' )
				?.hasAttribute( 'hidden' )
		).toBe( true );
		passedToggle?.focus();
		expect( passedToggle?.ownerDocument.activeElement ).toBe(
			passedToggle
		);
		act( () => {
			passedToggle?.dispatchEvent(
				new KeyboardEvent( 'keydown', {
					key: 'Enter',
					bubbles: true,
				} )
			);
			passedToggle?.click();
		} );
		expect( passedToggle?.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect(
			container
				.querySelector( '#seostudio-passed-checks' )
				?.hasAttribute( 'hidden' )
		).toBe( false );

		const lengthToggle = button( 'SEO title length' );
		const lengthPanel = container.querySelector(
			'[id="seostudio-check-title.length"]'
		);
		expect( lengthToggle?.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect( lengthPanel?.hasAttribute( 'hidden' ) ).toBe( true );
		act( () => {
			lengthToggle?.click();
		} );
		expect( lengthToggle?.getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect( lengthPanel?.hasAttribute( 'hidden' ) ).toBe( false );
		expect( lengthPanel?.textContent ).toContain( 'The title is short.' );
		expect( container.textContent ).toContain( 'Measured: 12' );
		expect( container.textContent ).toContain( 'Rewrite the title' );
		expect( container.querySelector( 'a' )?.getAttribute( 'href' ) ).toBe(
			'post.php?post=4&action=edit'
		);
		expect( container.textContent ).not.toContain(
			'No measured value was stored.'
		);

		act( () => {
			button( 'Add focus keyphrase' )?.click();
		} );
		expect( phrases ).toEqual( [ 'focus' ] );
		expect( container.textContent ).toContain(
			'Add a focus keyphrase below.'
		);
		act( () => {
			button( 'Check published page' )?.click();
		} );
		expect( published ).toEqual( [ 'live' ] );
		expect(
			container
				.querySelector( '#seostudio-other-checks' )
				?.hasAttribute( 'hidden' )
		).toBe( true );
		act( () => {
			button( 'Show all checks' )?.click();
		} );
		expect(
			container
				.querySelector( '#seostudio-other-checks' )
				?.hasAttribute( 'hidden' )
		).toBe( false );
		expect( container.textContent ).toContain( "Doesn't apply" );
		act( () => {
			root.unmount();
		} );
		container.remove();
	} );

	it( 'hides stored checks while a preview is provisional', () => {
		const html = renderToStaticMarkup(
			<EvidenceReadiness
				presentation={ presentation }
				provisional
				onCheckPublished={ () => undefined }
				onAddKeyphrase={ () => undefined }
			>
				<p>Preview</p>
			</EvidenceReadiness>
		);
		expect( html ).toContain( 'Preview' );
		expect( html ).not.toContain( 'Hidden from search' );
		expect( html ).not.toContain( 'View safe fix' );
	} );
} );
