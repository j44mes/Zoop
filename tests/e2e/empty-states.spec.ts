import type { Page, Route } from '@playwright/test';
import {
	expect,
	expectRendered,
	openDashboard,
	test,
	waitForPageSearch,
} from './fixtures';

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

function suggestionsRegion( page: Page ) {
	return page.getByRole( 'region', {
		name: 'Suggested next steps',
		exact: true,
	} );
}

function pagesRegion( page: Page ) {
	return page.getByRole( 'region', { name: 'Pages', exact: true } );
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
	await openDashboard( page );
	const pages = pagesRegion( page );
	await expectRendered( page, async () => {
		await expect( pages.getByRole( 'table' ) ).toBeVisible();
	} );
	const searched = waitForPageSearch( page, 'missing-page' );
	await pages.getByLabel( 'Search' ).fill( 'missing-page' );
	await searched;
	await expectRendered( page, async () => {
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
	await openDashboard( page );
	const firstRun = page.getByRole( 'region', {
		name: 'No pages checked yet',
	} );
	await expectRendered( page, async () => {
		await expect(
			firstRun.getByRole( 'heading', { name: 'No pages checked yet' } )
		).toBeVisible();
		await expect(
			firstRun.getByRole( 'button', { name: 'Open a page' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'Suggested next steps' } )
		).toHaveCount( 0 );
		await expect( suggestionsRegion( page ) ).toHaveCount( 0 );
		await expect( pagesRegion( page ) ).toHaveCount( 0 );
		await expect( page.getByRole( 'table' ) ).toHaveCount( 0 );
		await expect(
			page.getByText( 'Nothing to fix right now.' )
		).toHaveCount( 0 );
	} );
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
	await openDashboard( page );
	const pages = pagesRegion( page );
	const suggestions = suggestionsRegion( page );
	await expectRendered( page, async () => {
		await expect( pages.getByRole( 'table' ) ).toBeVisible();
		await expect(
			pages.getByRole( 'cell', { name: /Stored unscored page/ } )
		).toBeVisible();
		await expect(
			suggestions.getByText(
				'No suggestions yet. Check a page to get some.'
			)
		).toBeVisible();
		await expect(
			suggestions.getByText( 'Nothing to fix right now.' )
		).toHaveCount( 0 );
		await expect(
			page.getByRole( 'region', { name: 'No pages checked yet' } )
		).toHaveCount( 0 );
	} );
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
	await openDashboard( page );
	await expectRendered( page, async () => {
		await expect(
			suggestionsRegion( page ).getByText( 'Nothing to fix right now.' )
		).toBeVisible();
	} );

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
	await openDashboard( page, 'failed' );
	await expectRendered( page, async () => {
		await expect(
			suggestionsRegion( page ).getByRole( 'alert' ).filter( {
				hasText: 'Suggestions could not be loaded.',
			} )
		).toBeVisible();
		await expect(
			suggestionsRegion( page ).getByText( 'Nothing to fix right now.' )
		).toHaveCount( 0 );
	} );
} );

test( 'an incomplete review is not an all-clear', async ( { page } ) => {
	const unchecked = storedPage();
	unchecked.title = 'Unchecked stored page';
	unchecked.score_state = 'not_analysed';
	const notice =
		"No fixes found on the 3 checked pages. 23 pages haven't been checked yet.";
	await page.route( pagesApi, ( route ) => {
		const url = requestUrl( route );
		if ( url.includes( 'suggestions=' ) ) {
			return route.fulfill( {
				json: {
					items: [],
					total: 3,
					pages: 1,
					summary: summary( {
						analysed: 26,
						scored: 3,
						suggestion_state: 'incomplete',
						suggestion_notice: notice,
						checked: 3,
						unchecked: 23,
					} ),
				},
			} );
		}
		if ( url.includes( 'score_state=unchecked' ) ) {
			return route.fulfill( {
				json: {
					items: [ unchecked ],
					total: 23,
					pages: 2,
					summary: summary( { analysed: 23, not_analysed: 23 } ),
				},
			} );
		}
		return route.fulfill( {
			json: {
				items: [ storedPage() ],
				total: 26,
				pages: 2,
				summary: summary( {
					analysed: 26,
					scored: 3,
					not_analysed: 23,
				} ),
			},
		} );
	} );
	await openDashboard( page );
	const suggestions = suggestionsRegion( page );
	await expectRendered( page, async () => {
		await expect( suggestions.getByText( notice ) ).toBeVisible();
		await expect(
			suggestions.getByText( 'Nothing to fix right now.' )
		).toHaveCount( 0 );
		await expect(
			suggestions.getByRole( 'button', { name: 'Show unchecked pages' } )
		).toBeVisible();
	} );
	await suggestions
		.getByRole( 'button', { name: 'Show unchecked pages' } )
		.click();
	const row = pagesRegion( page ).getByRole( 'row', {
		name: /Unchecked stored page/,
	} );
	await expect( row ).toBeVisible();
	await expect(
		row.getByRole( 'button', { name: 'Check published page' } )
	).toBeVisible();
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
	await openDashboard( page );
	await expectRendered( page, async () => {
		await expect(
			suggestionsRegion( page ).getByRole( 'alert' ).filter( {
				hasText: 'Suggestions could not be loaded.',
			} )
		).toBeVisible();
		await expect(
			suggestionsRegion( page ).getByText( 'Nothing to fix right now.' )
		).toHaveCount( 0 );
	} );
} );
