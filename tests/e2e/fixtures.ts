import {
	test as base,
	expect,
	type Page,
	type Response,
} from '@playwright/test';

export { expect };

type Stage = 'authentication' | 'response sequencing' | 'rendering';

const pagesApi = /seostudio(?:\/|%2F)v1(?:\/|%2F)pages/i;

export function isPagesRequest( url: string ): boolean {
	return pagesApi.test( url );
}

export function isSuggestionRequest( url: string ): boolean {
	return decodeURIComponent( url ).includes( 'suggestions=' );
}

async function fail(
	page: Page,
	stage: Stage,
	message: string,
	cause?: unknown
): Promise< never > {
	const detail =
		cause instanceof Error ? `${ message } ${ cause.message }` : message;
	await base.info().attach( 'failure-stage', {
		body: `${ stage }\n${ page.url() }\n${ detail }`,
		contentType: 'text/plain',
	} );
	throw new Error( `${ stage }: ${ detail }` );
}

/**
 * WordPress account screen. A redirect to wp-login.php is an authentication failure.
 *
 * @param page Authenticated browser page.
 */
export async function assertWordPressAdmin( page: Page ): Promise< void > {
	let profile;
	try {
		profile = await page.request.get( '/wp-admin/profile.php', {
			maxRedirects: 0,
		} );
	} catch ( error ) {
		await fail(
			page,
			'authentication',
			'WordPress profile could not be requested.',
			error
		);
	}
	const location = profile.headers().location ?? '';
	if ( profile.status() >= 300 && profile.status() < 400 ) {
		await fail(
			page,
			'authentication',
			`profile.php redirected to ${ location || 'another address' }.`
		);
	}
	if ( profile.status() !== 200 ) {
		await fail(
			page,
			'authentication',
			`profile.php returned ${ profile.status() }.`
		);
	}
	const html = await profile.text();
	if ( ! html.includes( 'profile-php' ) ) {
		await fail(
			page,
			'authentication',
			'profile.php did not render the WordPress account screen.'
		);
	}
}

async function settled(
	page: Page,
	label: string,
	wait: Promise< unknown >
): Promise< void > {
	try {
		await wait;
	} catch ( error ) {
		await fail( page, 'response sequencing', label, error );
	}
}

/**
 * Open Zoop after routes are registered. The test continues when the page-list
 * and suggestion requests have settled, including an expected failed suggestion request.
 *
 * @param page        Authenticated browser page.
 * @param suggestions Whether the suggestion request should succeed or fail.
 */
export async function openDashboard(
	page: Page,
	suggestions: 'response' | 'failed' = 'response'
): Promise< void > {
	const list = page.waitForResponse(
		( response: Response ) =>
			isPagesRequest( response.url() ) &&
			! isSuggestionRequest( response.url() ),
		{ timeout: 20000 }
	);
	const suggestion =
		suggestions === 'failed'
			? page.waitForEvent( 'requestfailed', {
					predicate: ( request ) =>
						isPagesRequest( request.url() ) &&
						isSuggestionRequest( request.url() ),
					timeout: 20000,
			  } )
			: page.waitForResponse(
					( response: Response ) =>
						isPagesRequest( response.url() ) &&
						isSuggestionRequest( response.url() ),
					{ timeout: 20000 }
			  );

	await page.goto( '/wp-admin/admin.php?page=seostudio', {
		waitUntil: 'domcontentloaded',
	} );
	if ( page.url().includes( 'wp-login.php' ) ) {
		await fail(
			page,
			'authentication',
			'Zoop opened the WordPress login screen.'
		);
	}
	await settled( page, 'the page-list request did not finish.', list );
	await settled(
		page,
		suggestions === 'failed'
			? 'the suggestion request was expected to fail and did not.'
			: 'the suggestion request did not finish.',
		suggestion
	);
}

export async function waitForPageSearch(
	page: Page,
	term: string
): Promise< void > {
	await settled(
		page,
		`the page-list request for "${ term }" did not finish.`,
		page.waitForResponse(
			( response: Response ) => {
				const url = decodeURIComponent( response.url() );
				return (
					isPagesRequest( response.url() ) &&
					! isSuggestionRequest( response.url() ) &&
					url.includes( `search=${ term }` )
				);
			},
			{ timeout: 20000 }
		)
	);
}

export async function expectRendered(
	page: Page,
	check: () => Promise< void >
): Promise< void > {
	if ( page.url().includes( 'wp-login.php' ) ) {
		await fail(
			page,
			'authentication',
			'Zoop opened the WordPress login screen.'
		);
	}
	try {
		await check();
	} catch ( error ) {
		if ( page.url().includes( 'wp-login.php' ) ) {
			await fail(
				page,
				'authentication',
				'Zoop opened the WordPress login screen.',
				error
			);
		}
		await fail(
			page,
			'rendering',
			'the dashboard did not show the expected region.',
			error
		);
	}
}

export const test = base.extend( {
	page: async ( { page }, use ) => {
		await assertWordPressAdmin( page );
		await use( page );
	},
} );
