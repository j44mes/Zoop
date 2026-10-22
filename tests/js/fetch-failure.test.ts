import { fetchFailureDetail } from '../../assets/src/shared/fetch-failure';

describe( 'published-page failure details', () => {
	it( 'keeps the reason code out of the notice model', () => {
		const detail = fetchFailureDetail( {
			code: 'seostudio_fetch_blocked',
			message:
				"Couldn't check the published page. Your host returned a protection page (HTTP 202). Password protection or a bot check may be blocking Zoop.",
			data: {
				status: 400,
				reason: 'protection_page',
				http_status: 202,
				fetch_guidance_url:
					'https://github.com/j44mes/Zoop/blob/main/docs/letting-zoop-through.md',
				retained_notice:
					'Last checked successfully on 21 October 2026. The latest check on 22 October 2026 failed, so Zoop is still showing the earlier result.',
			},
		} );

		expect( detail ).toEqual( {
			message:
				"Couldn't check the published page. Your host returned a protection page (HTTP 202). Password protection or a bot check may be blocking Zoop.",
			retainedNotice:
				'Last checked successfully on 21 October 2026. The latest check on 22 October 2026 failed, so Zoop is still showing the earlier result.',
			guidanceUrl:
				'https://github.com/j44mes/Zoop/blob/main/docs/letting-zoop-through.md',
			retry: true,
		} );
		expect( JSON.stringify( detail ) ).not.toContain( 'protection_page' );
	} );

	it( 'offers a retry for an ordinary HTTP failure without a host-help link', () => {
		const detail = fetchFailureDetail( {
			code: 'seostudio_fetch_blocked',
			message:
				"Couldn't check the published page. The host refused the request (HTTP 403).",
			data: {
				reason: 'http_status',
				http_status: 403,
			},
		} );

		expect( detail?.retry ).toBe( true );
		expect( detail?.guidanceUrl ).toBe( '' );
		expect( detail?.message ).not.toContain( 'protection page' );
	} );

	it( 'does not treat a missing phrase as a published-page failure', () => {
		expect(
			fetchFailureDetail( {
				code: 'seostudio_validation',
				message: 'A keyphrase is required.',
			} )
		).toBeNull();
	} );
} );
