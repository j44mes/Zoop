import {
	currentProposalRevision,
	proposalRevisionHeaders,
	setProposalRevision,
} from '../../assets/src/editor/proposal-revision';

describe( 'proposal revision header', () => {
	it( 'adds the loaded revision to a native post or page update', () => {
		setProposalRevision( '6' );
		expect( currentProposalRevision() ).toBe( '6' );
		expect(
			proposalRevisionHeaders( '/wp/v2/posts/15?_locale=user', 'PUT' )
		).toEqual( { 'X-Zoop-Proposal-Revision': '6' } );
		expect( proposalRevisionHeaders( '/wp/v2/pages/8', 'POST' ) ).toEqual( {
			'X-Zoop-Proposal-Revision': '6',
		} );
	} );

	it( 'leaves autosaves and other requests unchanged', () => {
		setProposalRevision( '2' );
		expect(
			proposalRevisionHeaders( '/wp/v2/posts/15/autosaves', 'POST' )
		).toBeNull();
		expect(
			proposalRevisionHeaders( '/wp/v2/posts/15/autosaves/3', 'PUT' )
		).toBeNull();
		expect( proposalRevisionHeaders( '/wp/v2/posts', 'POST' ) ).toBeNull();
		expect(
			proposalRevisionHeaders( '/seostudio/v1/posts/15/seo', 'PUT' )
		).toBeNull();
		expect(
			proposalRevisionHeaders( '/wp/v2/posts/15', 'GET' )
		).toBeNull();
	} );
} );
