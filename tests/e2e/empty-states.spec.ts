import { expect, test, type Page, type Route } from '@playwright/test';

async function login( page: Page ): Promise< void > {
	// WebKit can drop a password fill into the username field. A lost session also
	// returns as wp-login.php?reauth=1, so confirm the values and try once more.
	const target =
		'/wp-login.php?redirect_to=' +
		encodeURIComponent( '/wp-admin/admin.php?page=seostudio' );
	for ( let attempt = 0; attempt < 2; attempt++ ) {
		await page.goto( target, { waitUntil: 'domcontentloaded' } );
		if ( page.url().includes( '/wp-admin/' ) ) {
			return;
		}
		const user = page.locator( '#user_login' );
		const pass = page.locator( '#user_pass' );
		await user.click();
		await user.fill( 'admin' );
		await pass.click();
		await pass.fill( 'password' );
		if (
			( await user.inputValue() ) !== 'admin' ||
			( await pass.inputValue() ) !== 'password'
		) {
			await page.locator( '#loginform' ).evaluate( ( form ) => {
				const loginField = form.querySelector( '#user_login' );
				const passwordField = form.querySelector( '#user_pass' );
				if ( loginField instanceof HTMLInputElement ) {
					loginField.value = 'admin';
				}
				if ( passwordField instanceof HTMLInputElement ) {
					passwordField.value = 'password';
				}
			} );
		}
		await page.locator( '#wp-submit' ).click();
		try {
			await page.waitForURL( '**/wp-admin/**', {
				timeout: 20000,
				waitUntil: 'commit',
			} );
			return;
		} catch ( error ) {
			if ( attempt === 1 ) {
				throw error;
			}
		}
	}
}

function summary(
	overrides: Record< string, unknown > = {}
): Record< string, unknown > {
	return {
		analysed: 0,
		scored: 0,
		unscored: 0,
		needs_reanalysis: 0,
		not_analysed: 0,
		failed: 0,
		average: null,
		red: 0,
		orange: 0,
		green: 0,
		reanalysis_notice: '0 pages have changed or need checking again.',
		search_discouraged: false,
		...overrides,
	};
}

function storedPage(): Record< string, unknown > {
	return {
		page_id: 8,
		object_id: 8,
		title: 'Stored unscored page',
		url: 'https://example.test/stored/',
		edit_url: 'post.php?post=8&action=edit',
		keyphrase: 'rank tracker',
		onpage: { score: null, denominator: null },
		seo_rating: null,
		readability_rating: null,
		top_issue: '',
		score_state: 'unscored',
		last_rendered_at: null,
		last_error: null,
	};
}

const pagesApi = /seostudio(?:\/|%2F)v1(?:\/|%2F)pages/i;

function requestUrl( route: Route ): string {
	return decodeURIComponent( route.request().url() );
}

async function fulfillPages(
	route: Route,
	list: Record< string, unknown >,
	suggestions: Record< string, unknown > | 'fail'
): Promise< void > {
	const url = requestUrl( route );
	if ( url.includes( 'suggestions=' ) ) {
		if ( suggestions === 'fail' ) {
			await route.abort();
			return;
		}
		await route.fulfill( { json: suggestions } );
		return;
	}
	await route.fulfill( { json: list } );
}

test( 'a filtered list stays distinct from a fresh site', async ( {
	page,
} ) => {
	const row = storedPage();
	await page.route( pagesApi, ( route ) => {
		const url = requestUrl( route );
		if ( url.includes( 'suggestions=' ) ) {
			return route.fulfill( {
				json: {
					items: [],
					total: 0,
					pages: 1,
					summary: summary( { suggestion_state: 'unscored' } ),
				},
			} );
		}
		if ( url.includes( 'search=' ) ) {
			return route.fulfill( {
				json: {
					items: [],
					total: 0,
					pages: 1,
					summary: summary(),
				},
			} );
		}
		return route.fulfill( {
			json: {
				items: [ row ],
				total: 1,
				pages: 1,
				summary: summary( { analysed: 1, unscored: 1 } ),
			},
		} );
	} );
	await login( page );
	await page.goto( '/wp-admin/admin.php?page=seostudio' );
	const pages = page.getByRole( 'region', { name: 'Pages', exact: true } );
	await expect( pages.getByRole( 'table' ) ).toBeVisible();
	await pages.getByLabel( 'Search' ).fill( 'missing-page' );
	await expect(
		pages.getByText( 'No stored pages match these filters.' )
	).toBeVisible();
	await expect(
		pages.getByText(
			'No pages are stored yet. Open a page and choose Analyse page.'
		)
	).toHaveCount( 0 );
	await expect(
		page.getByRole( 'region', { name: 'No pages checked yet' } )
	).toHaveCount( 0 );
} );

test( 'a fresh site shows one first-run card', async ( { page } ) => {
	await page.route( pagesApi, ( route ) =>
		fulfillPages(
			route,
			{
				items: [],
				total: 0,
				pages: 1,
				summary: summary(),
			},
			{
				items: [],
				total: 0,
				pages: 1,
				summary: summary( { suggestion_state: 'unscored' } ),
			}
		)
	);
	await login( page );
	await page.goto( '/wp-admin/admin.php?page=seostudio' );
	const firstRun = page.getByRole( 'region', {
		name: 'No pages checked yet',
	} );
	await expect(
		firstRun.getByRole( 'heading', { name: 'No pages checked yet' } )
	).toBeVisible();
	await expect(
		firstRun.getByRole( 'button', { name: 'Open a page' } )
	).toBeVisible();
	await expect(
		page.getByRole( 'heading', { name: 'Suggested next steps' } )
	).toHaveCount( 0 );
	await expect(
		page.getByRole( 'region', { name: 'Pages', exact: true } )
	).toHaveCount( 0 );
	await expect( page.getByRole( 'table' ) ).toHaveCount( 0 );
	await expect( page.getByText( 'Nothing to fix right now.' ) ).toHaveCount(
		0
	);
} );

test( 'stored pages without a definitive score do not look finished', async ( {
	page,
} ) => {
	const row = storedPage();
	await page.route( pagesApi, ( route ) =>
		fulfillPages(
			route,
			{
				items: [ row ],
				total: 1,
				pages: 1,
				summary: summary( {
					analysed: 1,
					unscored: 1,
				} ),
			},
			{
				items: [],
				total: 0,
				pages: 1,
				summary: summary( {
					analysed: 0,
					suggestion_state: 'unscored',
				} ),
			}
		)
	);
	await login( page );
	await page.goto( '/wp-admin/admin.php?page=seostudio' );
	const pages = page.getByRole( 'region', { name: 'Pages', exact: true } );
	await expect( pages.getByRole( 'table' ) ).toBeVisible();
	await expect(
		pages.getByRole( 'cell', { name: /Stored unscored page/ } )
	).toBeVisible();
	await expect(
		page.getByText( 'No suggestions yet. Check a page to get some.' )
	).toBeVisible();
	await expect( page.getByText( 'Nothing to fix right now.' ) ).toHaveCount(
		0
	);
	await expect(
		page.getByRole( 'region', { name: 'No pages checked yet' } )
	).toHaveCount( 0 );
} );

test( 'a clear suggestion state is explicit and a failed load is not all-clear', async ( {
	page,
} ) => {
	const row = storedPage();
	await page.route( pagesApi, ( route ) =>
		fulfillPages(
			route,
			{
				items: [ row ],
				total: 1,
				pages: 1,
				summary: summary( { analysed: 1, scored: 1, green: 1 } ),
			},
			{
				items: [],
				total: 1,
				pages: 1,
				summary: summary( {
					analysed: 1,
					scored: 1,
					green: 1,
					suggestion_state: 'clear',
				} ),
			}
		)
	);
	await login( page );
	await page.goto( '/wp-admin/admin.php?page=seostudio' );
	await expect( page.getByText( 'Nothing to fix right now.' ) ).toBeVisible();

	await page.unroute( pagesApi );
	await page.route( pagesApi, ( route ) =>
		fulfillPages(
			route,
			{
				items: [ row ],
				total: 1,
				pages: 1,
				summary: summary( { analysed: 1, scored: 1, green: 1 } ),
			},
			'fail'
		)
	);
	await page.goto( '/wp-admin/admin.php?page=seostudio' );
	await expect(
		page.getByText( 'Suggestions could not be loaded.' )
	).toBeVisible();
	await expect( page.getByText( 'Nothing to fix right now.' ) ).toHaveCount(
		0
	);
} );

test( 'an empty suggestion response without a server state is an error', async ( {
	page,
} ) => {
	const row = storedPage();
	await page.route( pagesApi, ( route ) =>
		fulfillPages(
			route,
			{
				items: [ row ],
				total: 1,
				pages: 1,
				summary: summary( { analysed: 1, scored: 1 } ),
			},
			{
				items: [],
				total: 0,
				pages: 1,
				summary: summary( { analysed: 0 } ),
			}
		)
	);
	await login( page );
	await page.goto( '/wp-admin/admin.php?page=seostudio' );
	await expect(
		page.getByRole( 'alert' ).filter( {
			hasText: 'Suggestions could not be loaded.',
		} )
	).toBeVisible();
	await expect( page.getByText( 'Nothing to fix right now.' ) ).toHaveCount(
		0
	);
} );
