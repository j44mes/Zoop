import { expect, test, type Page } from '@playwright/test';

test.describe.configure( { mode: 'serial' } );

let editedPostUrl = '';

async function login( page: Page ): Promise< void > {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', 'admin' );
	await page.fill( '#user_pass', 'password' );
	await page.click( '#wp-submit' );
	await page.waitForURL( '**/wp-admin/**' );
}

async function editorDiagnostics( page: Page ): Promise< string > {
	const details = await page.evaluate( () => {
		const wp = (
			window as unknown as {
				wp?: {
					plugins?: { getPlugin?: ( name: string ) => unknown };
					data?: {
						select?: ( store: string ) => {
							getActiveComplementaryArea?: (
								scope: string
							) => string | null | undefined;
						};
					};
				};
				seostudioEditor?: unknown;
			}
		 ).wp;
		const script = document.getElementById( 'seostudio-editor-js' );
		return {
			config:
				( window as unknown as { seostudioEditor?: unknown } )
					.seostudioEditor ?? null,
			registered: Boolean( wp?.plugins?.getPlugin?.( 'seostudio' ) ),
			active:
				wp?.data
					?.select?.( 'core/interface' )
					?.getActiveComplementaryArea?.( 'core' ) ?? null,
			script: script?.getAttribute( 'src' ) ?? '',
		};
	} );
	return JSON.stringify( details );
}

function watchPageErrors( page: Page ): string[] {
	const errors: string[] = [];
	page.on( 'pageerror', ( error ) => {
		errors.push( error.message );
	} );
	return errors;
}

async function openSeoSidebar( page: Page, errors: string[] ): Promise< void > {
	try {
		await page.waitForFunction(
			() =>
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
				),
			undefined,
			{ timeout: 20000 }
		);
	} catch ( error ) {
		throw new Error(
			`SEO plugin did not register. ${ await editorDiagnostics(
				page
			) } ${ errors.join( ' | ' ) }\n${ error }`
		);
	}
	await page.evaluate( () => {
		const dispatch = (
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
		 ).wp.data.dispatch( 'core/interface' );
		dispatch.enableComplementaryArea?.( 'core', 'seostudio/seostudio' );
	} );
	try {
		await expect( page.getByLabel( 'Keyphrase' ) ).toBeVisible( {
			timeout: 20000,
		} );
	} catch ( error ) {
		throw new Error(
			`SEO sidebar did not open. ${ await editorDiagnostics(
				page
			) } ${ errors.join( ' | ' ) }\n${ error }`
		);
	}
}

async function saveDraft( page: Page ): Promise< void > {
	await page.evaluate( () => {
		const editor = (
			window as unknown as {
				wp: {
					data: {
						dispatch: ( store: string ) => {
							savePost: () => Promise< unknown >;
						};
					};
				};
			}
		 ).wp.data.dispatch( 'core/editor' );
		return editor.savePost();
	} );
	await page.waitForFunction( () => {
		const select = (
			window as unknown as {
				wp: {
					data: {
						select: ( store: string ) => {
							getCurrentPostId: () => number;
							isSavingPost: () => boolean;
						};
					};
				};
			}
		 ).wp.data.select( 'core/editor' );
		return select.getCurrentPostId() > 0 && ! select.isSavingPost();
	} );
	await page.waitForURL( /post=\d+/ );
}

test( 'dashboard is available after activation', async ( { page } ) => {
	await login( page );
	await page.goto( '/wp-admin/admin.php?page=seostudio' );
	await expect(
		page.getByRole( 'heading', { name: 'SEO Studio' } )
	).toBeVisible();
	await expect(
		page.getByText( 'No pages have been analysed yet' )
	).toBeVisible();
} );

test( 'the block editor accepts a keyphrase and shows both scores', async ( {
	page,
} ) => {
	const errors = watchPageErrors( page );
	await login( page );
	await page.goto( '/wp-admin/post-new.php' );
	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	await canvas.getByLabel( 'Add title' ).fill( 'Welcome note' );
	await saveDraft( page );
	editedPostUrl = page.url();
	await openSeoSidebar( page, errors );
	await page.getByLabel( 'Keyphrase' ).fill( 'rank tracker' );
	await page.getByRole( 'button', { name: 'Analyse now' } ).click();
	await expect( page.getByTestId( 'onpage-score' ) ).toBeVisible();
	await expect( page.getByTestId( 'seo-light' ) ).toBeVisible();
	await expect( page.getByTestId( 'readability-light' ) ).toBeVisible();
} );

test( 'editing the SEO title updates the title assessment without a reload', async ( {
	page,
} ) => {
	const errors = watchPageErrors( page );
	await login( page );
	await page.goto( editedPostUrl );
	await openSeoSidebar( page, errors );
	const titleCheck = page.locator(
		'details[data-assessment="title.entire_keyphrase"]'
	);
	await titleCheck.locator( 'summary' ).click();
	await expect(
		titleCheck.getByText( 'Include the full keyphrase in the title.' )
	).toBeVisible();
	await page.getByLabel( 'SEO title' ).fill( 'rank tracker welcome' );
	await expect(
		page.getByText( 'The full keyphrase is in the title.' )
	).toBeVisible();
} );

test( 're-analyse all reports that the queue is not available', async ( {
	page,
} ) => {
	await login( page );
	await page.goto( '/wp-admin/admin.php?page=seostudio' );
	await page.getByRole( 'button', { name: 'Re-analyse all' } ).click();
	await expect(
		page.getByText( 'The site audit queue is not available in this phase.' )
	).toBeVisible();
} );
