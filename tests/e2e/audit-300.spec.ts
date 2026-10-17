import { expect, test, type Page } from '@playwright/test';

test.describe.configure( { mode: 'serial' } );

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

test( 'a site audit finishes 300 generated posts', async ( {
	page,
	browserName,
} ) => {
	test.skip(
		browserName !== 'chromium',
		'Chromium and WebKit share one site, and an audit can be started only once every ten minutes.'
	);
	test.setTimeout( 600000 );
	await login( page );
	await page.waitForFunction( () =>
		Boolean(
			(
				window as unknown as {
					wp?: { apiFetch?: unknown };
				}
			 ).wp?.apiFetch
		)
	);

	await page.evaluate( async () => {
		const wp = (
			window as unknown as {
				wp: {
					apiFetch: ( options: {
						path: string;
						method: string;
						data: {
							title: string;
							content: string;
							status: string;
						};
					} ) => Promise< unknown >;
				};
			}
		 ).wp;
		for ( let index = 0; index < 300; index++ ) {
			await wp.apiFetch( {
				path: '/wp/v2/posts',
				method: 'POST',
				data: {
					title: `Bulk audit ${ index }`,
					content: '<p>Body copy for the bulk audit.</p>',
					status: 'publish',
				},
			} );
		}
	} );

	const started = page.waitForResponse(
		( response ) =>
			decodeURIComponent( response.url() ).includes(
				'/seostudio/v1/audits'
			) && response.request().method() === 'POST'
	);
	await page.getByRole( 'button', { name: 'Re-analyse all' } ).click();
	const response = await started;
	expect( response.status() ).toBe( 202 );
	const body = ( await response.json() ) as { run_id?: number };
	expect( body.run_id ).toBeGreaterThan( 0 );

	let status = '';
	let processed = 0;
	let total = 0;
	let failed = 0;
	const deadline = Date.now() + 480000;
	while ( Date.now() < deadline ) {
		await page.goto( '/wp-admin/admin.php?page=seostudio', {
			waitUntil: 'domcontentloaded',
		} );
		const run = await page.evaluate( async ( runId ) => {
			return (
				window as unknown as {
					wp: {
						apiFetch: ( options: { path: string } ) => Promise< {
							status: string;
							processed: number;
							total: number;
							failed: number;
						} >;
					};
				}
			 ).wp.apiFetch( {
				path: `/seostudio/v1/audits/${ runId }`,
			} );
		}, body.run_id );
		status = run.status;
		processed = run.processed;
		total = run.total;
		failed = run.failed;
		if (
			status === 'done' ||
			status === 'failed' ||
			status === 'cancelled'
		) {
			break;
		}
	}

	expect( status ).toBe( 'done' );
	expect( total ).toBeGreaterThanOrEqual( 300 );
	expect( processed ).toBe( total );
	expect( failed ).toBe( 0 );
} );
