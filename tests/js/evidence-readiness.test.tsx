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
			action_label: 'Add keyphrase',
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
		expect( html ).toContain( 'Add keyphrase' );
		expect( html ).toContain( 'Passed' );
		expect( html ).toContain( 'Needs work' );
		expect( html ).toContain( 'Not checked' );
		expect( html ).toContain( 'Out of date' );
		expect( html ).toContain( 'View evidence' );
		expect( html ).toContain( 'View safe fix' );
		expect( html ).toContain( 'Check published page' );
		expect( html ).toContain( 'Measured' );
		expect( html ).toContain( 'Rank tracker' );
		expect( html ).toContain( 'Show all checks' );
		expect( html ).toContain( 'hidden=""' );
		expect( html ).toContain( 'Doesn&#x27;t apply' );
		expect( html ).not.toContain( 'post.php?post=4&action=edit' );
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
				( node ) => node.textContent === name
			);
		act( () => {
			button( 'Add keyphrase' )?.click();
		} );
		expect( phrases ).toEqual( [ 'focus' ] );
		act( () => {
			button( 'Check published page' )?.click();
		} );
		expect( published ).toEqual( [ 'live' ] );
		act( () => {
			button( 'View safe fix' )?.click();
		} );
		expect( container.innerHTML ).toContain( 'Rewrite the title' );
		expect(
			container
				.querySelector( '[id="seostudio-fix-title.length"] a' )
				?.getAttribute( 'href' )
		).toBe( 'post.php?post=4&action=edit' );
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
		expect( container.innerHTML ).toContain( "Doesn't apply" );
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
