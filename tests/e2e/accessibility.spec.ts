import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

async function login( page: Page ): Promise< void > {
	const target =
		'/wp-login.php?redirect_to=' +
		encodeURIComponent( '/wp-admin/admin.php?page=seostudio' );
	for ( let attempt = 0; attempt < 2; attempt++ ) {
		await page.goto( target, { waitUntil: 'domcontentloaded' } );
		if ( page.url().includes( '/wp-admin/' ) ) {
			return;
		}
		await page.locator( '#user_login' ).fill( 'admin' );
		await page.locator( '#user_pass' ).fill( 'password' );
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

test( 'the dashboard has no serious accessibility violations', async ( {
	page,
} ) => {
	await login( page );
	await page.goto( '/wp-admin/admin.php?page=seostudio' );
	await expect( page.getByRole( 'heading', { name: 'Zoop' } ) ).toBeVisible();
	const results = await new AxeBuilder( { page } )
		.include( '.seostudio-dashboard' )
		.analyze();
	const blocking = results.violations.filter(
		( violation ) =>
			violation.impact === 'serious' || violation.impact === 'critical'
	);
	expect( blocking ).toEqual( [] );
} );

test( 'the editor panel has no serious accessibility violations', async ( {
	page,
} ) => {
	await login( page );
	await page.goto( '/wp-admin/post.php?post=1&action=edit' );
	await page.evaluate( () => {
		const preferences = (
			window as unknown as {
				wp: {
					data: {
						dispatch: ( store: string ) => {
							set: (
								scope: string,
								key: string,
								value: unknown
							) => void;
						};
					};
				};
			}
		 ).wp.data.dispatch( 'core/preferences' );
		preferences.set( 'core/edit-post', 'welcomeGuide', false );
	} );
	await page.waitForFunction( () =>
		Boolean(
			(
				window as unknown as {
					wp?: {
						plugins?: {
							getPlugin?: ( name: string ) => unknown;
						};
					};
				}
			 ).wp?.plugins?.getPlugin?.( 'seostudio' )
		)
	);
	await page.evaluate( () => {
		(
			window as unknown as {
				wp: {
					data: {
						dispatch: ( store: string ) => {
							enableComplementaryArea?: (
								scope: string,
								identifier: string
							) => void;
						};
					};
				};
			}
		 ).wp.data
			.dispatch( 'core/interface' )
			.enableComplementaryArea?.( 'core', 'seostudio/seostudio' );
	} );
	await expect( page.locator( '.seostudio-panel' ) ).toBeVisible();
	const results = await new AxeBuilder( { page } )
		.include( '.seostudio-panel' )
		.analyze();
	const blocking = results.violations.filter(
		( violation ) =>
			violation.impact === 'serious' || violation.impact === 'critical'
	);
	expect( blocking ).toEqual( [] );
} );
