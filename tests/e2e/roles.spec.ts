import type { Page } from '@playwright/test';
import { assertSignedIn, expect, signIn, test } from './fixtures';

test.describe.configure( { mode: 'serial' } );

async function openAs(
	page: Page,
	username: string,
	password: string
): Promise< void > {
	await signIn( page, username, password );
	await page.goto( '/wp-admin/admin.php?page=seostudio', {
		waitUntil: 'domcontentloaded',
	} );
	await assertSignedIn( page );
}

async function createUser(
	page: Page,
	username: string,
	role: 'editor' | 'author'
): Promise< void > {
	await page.evaluate(
		async ( next: { username: string; role: 'editor' | 'author' } ) => {
			const api = (
				window as unknown as {
					wp: {
						apiFetch: ( options: {
							path: string;
							method: string;
							data: Record< string, unknown >;
						} ) => Promise< unknown >;
					};
				}
			 ).wp.apiFetch;
			await api( {
				path: '/wp/v2/users',
				method: 'POST',
				data: {
					username: next.username,
					email: `${ next.username }@example.test`,
					password: 'Zoop-Role-Pass-48fa',
					roles: [ next.role ],
				},
			} );
		},
		{ username, role }
	);
}

async function expectContributorNavigation( page: Page ): Promise< void > {
	const traffic: string[] = [];
	const record = ( request: {
		url: () => string;
		postData: () => string | null;
	} ) => {
		traffic.push( `${ request.url() }\n${ request.postData() || '' }` );
	};
	page.on( 'request', record );
	await page.goto( '/wp-admin/admin.php?page=seostudio', {
		waitUntil: 'domcontentloaded',
	} );
	await expect(
		page.getByRole( 'button', { name: 'Priorities', exact: true } )
	).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Settings', exact: true } )
	).toHaveCount( 0 );
	await expect(
		page.getByRole( 'button', { name: 'Architecture', exact: true } )
	).toHaveCount( 0 );
	await expect(
		page.getByRole( 'button', { name: 'Overlap', exact: true } )
	).toHaveCount( 0 );
	await expect(
		page.getByRole( 'button', { name: 'Search Console', exact: true } )
	).toHaveCount( 0 );
	await page
		.getByRole( 'button', { name: 'Priorities', exact: true } )
		.click();
	await expect(
		page.getByRole( 'heading', { name: 'Priorities', exact: true } )
	).toBeVisible();
	page.off( 'request', record );
	const urls = traffic.join( '\n' );
	expect( urls ).toContain( 'recommendations' );
	expect( urls ).not.toContain( '/seostudio/v1/settings' );
	expect( urls ).not.toContain( '/seostudio/v1/architecture' );
	expect( urls ).not.toContain( '/seostudio/v1/overlap' );
	expect( urls ).not.toContain( '/seostudio/v1/search-console' );
}

test( 'editors and authors can open Priorities without admin reports', async ( {
	page,
} ) => {
	const stamp = Date.now();
	const editor = `zoop_editor_${ stamp }`;
	const author = `zoop_author_${ stamp }`;
	await openAs( page, 'admin', 'password' );
	await page.waitForFunction(
		() =>
			!! (
				window as unknown as {
					wp?: { apiFetch?: unknown };
				}
			 ).wp?.apiFetch
	);
	await createUser( page, editor, 'editor' );
	await createUser( page, author, 'author' );

	await openAs( page, editor, 'Zoop-Role-Pass-48fa' );
	await expectContributorNavigation( page );

	await openAs( page, author, 'Zoop-Role-Pass-48fa' );
	await expectContributorNavigation( page );
} );
