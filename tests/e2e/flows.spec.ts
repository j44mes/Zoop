import { expect, test, type Page } from '@playwright/test';

test.describe.configure( { mode: 'serial' } );

let editedPostUrl = '';

async function login( page: Page ): Promise< void > {
	// Land on the plugin screen rather than the WordPress dashboard, whose widgets make outbound
	// requests. A lost session comes back as wp-login.php?reauth=1, so try once more.
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
		await expect(
			page.getByLabel( 'Keyphrase', { exact: true } )
		).toBeVisible( { timeout: 20000 } );
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

/**
 * Open an editor URL with the welcome guide dismissed. A fresh WordPress shows that modal, which
 * hides the rest of the editor from accessibility queries.
 *
 * @param page Playwright page.
 * @param url  Editor URL to open.
 */
async function openEditor( page: Page, url: string ): Promise< void > {
	await page.goto( url );
	await page.waitForFunction(
		() => !! ( window as unknown as { wp?: { data?: unknown } } ).wp?.data
	);
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
	const guide = page.getByRole( 'dialog' ).first();
	if ( await guide.isVisible() ) {
		await page.keyboard.press( 'Escape' );
		await expect( guide ).toBeHidden();
	}
}

test( 'dashboard is available after activation', async ( { page } ) => {
	await login( page );
	await page.goto( '/wp-admin/admin.php?page=seostudio' );
	await expect( page.getByRole( 'heading', { name: 'Zoop' } ) ).toBeVisible();
	// Browser projects share one WordPress database, so another project may already have analysed a page.
	await expect(
		page
			.getByText( 'No pages have been analysed yet' )
			.or( page.getByRole( 'table' ) )
	).toBeVisible();
} );

test( 'the block editor accepts a keyphrase and shows both scores', async ( {
	page,
} ) => {
	const errors = watchPageErrors( page );
	await login( page );
	await openEditor( page, '/wp-admin/post-new.php' );
	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	await canvas.getByLabel( 'Add title' ).fill( 'Welcome note' );
	await saveDraft( page );
	editedPostUrl = page.url();
	await openSeoSidebar( page, errors );
	await page
		.getByLabel( 'Keyphrase', { exact: true } )
		.fill( 'rank tracker' );
	await page.getByRole( 'button', { name: 'Analyse now' } ).click();
	await expect( page.getByTestId( 'onpage-score' ) ).toBeVisible();
	await expect( page.getByTestId( 'seo-light' ) ).toBeVisible();
	await expect( page.getByTestId( 'readability-light' ) ).toBeVisible();
	// Default Analysis-only mode must not claim the SEO Studio title is printed.
	await expect( page.getByTestId( 'metadata-note' ) ).toContainText(
		'saved but not printed'
	);
} );

test( 'editing the title updates the title assessment without a reload', async ( {
	page,
} ) => {
	const errors = watchPageErrors( page );
	await login( page );
	await openEditor( page, editedPostUrl );
	await openSeoSidebar( page, errors );
	const titleCheck = page.locator(
		'details[data-assessment="title.entire_keyphrase"]'
	);
	await titleCheck.locator( 'summary' ).click();
	await expect(
		titleCheck.getByText( 'Include the full keyphrase in the title.' )
	).toBeVisible();
	// Analysis-only mode prints the post title, so that is the title the score must follow.
	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	await canvas.getByLabel( 'Add title' ).fill( 'rank tracker welcome' );
	await expect(
		page.getByText( 'The full keyphrase is in the title.' )
	).toBeVisible();
} );

test( 're-analyse all queues an audit', async ( { page } ) => {
	await login( page );
	await page.goto( '/wp-admin/admin.php?page=seostudio' );
	await page.getByRole( 'button', { name: 'Re-analyse all' } ).click();
	await expect(
		page.getByText(
			/Audit queued\.|An audit is already running\.|An audit was started recently/
		)
	).toBeVisible();
} );

/**
 * Save the settings form and wait for the REST write, not only the notice.
 * WordPress also copies the notice into its screen-reader live region, so the
 * notice locator is scoped to the dashboard.
 *
 * @param page Playwright page.
 */
async function saveSettings( page: Page ): Promise< void > {
	const saved = page.waitForResponse(
		( response ) =>
			response.url().includes( 'seostudio/v1/settings' ) &&
			response.request().method() !== 'GET'
	);
	await page.getByRole( 'button', { name: 'Save settings' } ).click();
	expect( ( await saved ).ok() ).toBe( true );
	await expect(
		page.locator( '#seostudio-dashboard' ).getByText( 'Settings saved.' )
	).toBeVisible();
}

test( 'owner mode prints the home description', async ( { page } ) => {
	await login( page );
	const settingsUrl = '/wp-admin/admin.php?page=seostudio';
	try {
		await page.goto( settingsUrl );
		await page
			.getByRole( 'button', { name: 'Settings', exact: true } )
			.click();
		const settings = page.locator( '.seostudio-settings' );
		const mode = settings.getByLabel( 'Mode' );
		await mode.selectOption( 'owner' );
		await expect( mode ).toHaveValue( 'owner' );
		const homeDescription = settings.getByLabel( 'Home description' );
		await homeDescription.fill( 'Studio home description' );
		await homeDescription.blur();
		await saveSettings( page );
		await page.reload();
		await page
			.getByRole( 'button', { name: 'Settings', exact: true } )
			.click();
		await expect(
			page.locator( '.seostudio-settings' ).getByLabel( 'Mode' )
		).toHaveValue( 'owner' );
		const home = await page.request.get( '/' );
		expect( home.ok() ).toBe( true );
		expect( await home.text() ).toContain(
			'name="description" content="Studio home description"'
		);
	} finally {
		// Chromium and WebKit share one site. Restoring through the select can
		// save the previous mode, and the next browser then scores as Owner mode.
		await page.goto( settingsUrl );
		await page.waitForFunction( () =>
			Boolean(
				(
					window as unknown as {
						wp?: { apiFetch?: unknown };
					}
				 ).wp?.apiFetch
			)
		);
		const restored = await page.evaluate( async () => {
			const saved = await (
				window as unknown as {
					wp: {
						apiFetch: ( options: {
							path: string;
							method: string;
							data: { mode: string };
						} ) => Promise< { mode?: string } >;
					};
				}
			 ).wp.apiFetch( {
				path: '/seostudio/v1/settings',
				method: 'PUT',
				data: { mode: 'analysis' },
			} );
			return saved.mode;
		} );
		expect( restored ).toBe( 'analysis' );
	}
} );
