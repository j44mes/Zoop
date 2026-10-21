import {
	architectureStatus,
	depthLabel,
} from '../../assets/src/dashboard/architecture-copy';
import {
	evidenceEstablishes,
	evidenceSource,
	overlapConclusion,
	overlapConfidence,
	overlapObservation,
} from '../../assets/src/dashboard/overlap-copy';
import { priorityKind } from '../../assets/src/dashboard/priorities-copy';
import {
	topicIntentLabel,
	topicRoleLabel,
} from '../../assets/src/dashboard/topic-copy';
import { editHref, editTarget } from '../../assets/src/dashboard/edit-route';
import {
	imageKind,
	imageKindLabel,
	imageNote,
} from '../../assets/src/dashboard/image-report';
import {
	recommendationHeading,
	whyItMatters,
} from '../../assets/src/dashboard/recommendation-copy';
import { requestedSection } from '../../assets/src/editor/section';
import {
	appearancePreviewCaption,
	snippetCaption,
	socialCaption,
	workspaceOwnership,
} from '../../assets/src/dashboard/ownership-note';

describe( 'architecture copy', () => {
	it( 'names link states without treating a missing crawl as an orphan', () => {
		expect( architectureStatus( 'orphan' ) ).toBe( 'Orphan' );
		expect( architectureStatus( 'weak' ) ).toBe( 'Weakly linked' );
		expect( architectureStatus( 'template_only' ) ).toBe( 'Template only' );
		expect( architectureStatus( 'unknown' ) ).toBe( 'Not enough data' );
		expect( depthLabel( null ) ).toBe( 'Not reached' );
		expect( depthLabel( 2 ) ).toBe( '2' );
	} );
} );

describe( 'overlap copy', () => {
	it( 'keeps an observed phrase match apart from a competition conclusion', () => {
		expect( overlapObservation( 'phrase' ) ).toBe(
			'Confirmed phrase overlap'
		);
		expect( overlapConfidence( 'high' ) ).toBe( 'High' );
		expect( overlapConclusion( 'investigation' ) ).toBe(
			'Needs investigation'
		);
		expect( overlapConfidence( 'insufficient' ) ).toBe(
			'Insufficient evidence'
		);
		expect( evidenceEstablishes( 'same_observation' ) ).toBe(
			'Same observation'
		);
		expect( evidenceEstablishes( 'not_established' ) ).toBe(
			'Not established'
		);
		expect( evidenceSource( 'saved' ) ).toBe( 'Saved by the editor' );
		expect( evidenceSource( 'heading' ) ).toBe(
			'Suggested from the heading'
		);
		expect( evidenceSource( 'heading', true ) ).toBe(
			'Accepted from the heading'
		);
		expect( evidenceSource( 'editorial' ) ).toBe(
			'Specified by the editor'
		);
		expect( evidenceSource( 'title' ) ).toBe( 'Suggested from the title' );
	} );
} );

describe( 'priority copy', () => {
	it( 'names the evidence without calling it an automatic edit', () => {
		expect( priorityKind( 'overlap' ) ).toBe( 'Overlap' );
		expect( priorityKind( 'unresolved' ) ).toBe( 'Unresolved link' );
		expect( priorityKind( 'page' ) ).toBe( 'Link graph' );
		expect( priorityKind( 'search' ) ).toBe( 'Search evidence' );
	} );
} );

describe( 'topic copy', () => {
	it( 'labels a suggestion without calling it search data', () => {
		expect( topicRoleLabel( 'hub' ) ).toBe( 'Hub' );
		expect( topicRoleLabel( 'utility' ) ).toBe( 'Utility' );
		expect( topicIntentLabel( 'commercial' ) ).toBe( 'Commercial' );
		expect( topicIntentLabel( 'unknown' ) ).toBe( 'Unassigned' );
	} );
} );

describe( 'page workspace copy', () => {
	it( 'names the plugin that controls live tags', () => {
		expect(
			workspaceOwnership( {
				publishing: false,
				detected: { name: 'Yoast SEO' },
			} )
		).toContain( 'Yoast SEO controls the live tags' );
		expect(
			workspaceOwnership( { publishing: true, detected: null } )
		).toContain( 'Zoop manages the supported search and social tags' );
		expect( workspaceOwnership( null ) ).toContain( 'does not print them' );
	} );

	it( 'separates a live observation from a content analysis', () => {
		expect( appearancePreviewCaption() ).toBe(
			'Illustrative preview. Google may rewrite the title and description.'
		);
		expect( snippetCaption( 'live' ) ).toContain( 'published page' );
		expect( snippetCaption( 'content' ) ).toContain(
			'not the live metadata'
		);
		expect( socialCaption( false ) ).toContain( 'not the card published' );
		expect( socialCaption( true ) ).toContain( 'Zoop can print these' );
	} );

	it( 'routes a finding to an existing editor control', () => {
		expect( editTarget( 'title.entire_keyphrase' ).section ).toBe(
			'search'
		);
		expect( editTarget( 'code.stylesheet' ).liveCheck ).toBe( true );
		expect( editHref( 5, 'title.length' ) ).toBe(
			'post.php?post=5&action=edit&seostudio_section=search'
		);
		expect( editHref( 5, 'body.word_count' ) ).toBe(
			'post.php?post=5&action=edit'
		);
	} );

	it( 'keeps a CSS background out of the alt-text advice', () => {
		expect( imageKind( { src: '/a.png', in_body: true } ) ).toBe( 'html' );
		expect( imageKindLabel( 'css_background' ) ).toBe( 'CSS background' );
		expect(
			imageNote( {
				kind: 'css_background',
				src: '/hero.jpg',
				alt: 'rank tracker',
			} )
		).toContain( 'not part of the image-description score' );
		expect(
			imageNote( { kind: 'html', src: '/door.jpg', alt: '' } )
		).toContain( 'decorative' );
	} );

	it( 'names a finding as an action without promising a ranking', () => {
		expect( recommendationHeading( 'title.length' ) ).toBe(
			'Review the SEO title'
		);
		expect( whyItMatters( 'title.entire_keyphrase' ) ).toContain(
			'not predicting a ranking'
		);
		expect( whyItMatters( 'body.word_count' ) ).toContain(
			'not an instruction to repeat it'
		);
	} );

	it( 'keeps a section after the editor strips the query argument', () => {
		expect( requestedSection( 'search', '?post=5&action=edit' ) ).toBe(
			'search'
		);
		expect(
			requestedSection(
				'',
				'?post=5&action=edit&seostudio_section=phrases'
			)
		).toBe( 'phrases' );
		expect( requestedSection( 'homepage', '' ) ).toBe( '' );
	} );
} );
