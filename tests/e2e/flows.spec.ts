import type { Page } from '@playwright/test';
import { assertSignedIn, expect, test } from './fixtures';

test.describe.configure( { mode: 'serial' } );

let editedPostUrl = '';

function versionLabel(): RegExp {
	if ( process.env.REQUIRE_BUILD_STAMP === '1' ) {
		return /Zoop 0\.6\.0-dev\.1 \(build [0-9a-f]{7}\)/;
	}
	return /Zoop 0\.6\.0-dev\.1(?: \(build [0-9a-f]{7}\))?/;
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
			page.getByLabel( 'Focus keyphrase', { exact: true } )
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
	await page.goto( url, { waitUntil: 'domcontentloaded' } );
	await assertSignedIn( page );
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
	await page.goto( '/wp-admin/admin.php?page=seostudio', {
		waitUntil: 'domcontentloaded',
	} );
	await assertSignedIn( page );
	await expect( page.getByRole( 'heading', { name: 'Zoop' } ) ).toBeVisible();
	await expect( page.locator( '.seostudio-version' ).first() ).toHaveText(
		versionLabel()
	);
	// Browser projects share one WordPress database, so a later project may already have stored a page.
	const firstRun = page.getByRole( 'region', {
		name: 'No pages checked yet',
	} );
	const pages = page.getByRole( 'region', { name: 'Pages', exact: true } );
	await expect(
		firstRun
			.getByRole( 'heading', { name: 'No pages checked yet' } )
			.or( pages.getByRole( 'table' ) )
	).toBeVisible();
	await expect( page.getByTestId( 'ruleset-upgrade-notice' ) ).toHaveCount(
		0
	);
	if ( await firstRun.isVisible() ) {
		await expect(
			firstRun.getByRole( 'button', { name: 'Open a page' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'Suggested next steps' } )
		).toHaveCount( 0 );
		await expect( pages ).toHaveCount( 0 );
	}
} );

test( 'the block editor accepts a keyphrase and shows both scores', async ( {
	page,
} ) => {
	const errors = watchPageErrors( page );
	await openEditor( page, '/wp-admin/post-new.php' );
	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	await canvas.getByLabel( 'Add title' ).fill( 'Welcome note' );
	await saveDraft( page );
	editedPostUrl = page.url();
	await openSeoSidebar( page, errors );
	await page
		.getByLabel( 'Focus keyphrase', { exact: true } )
		.fill( 'rank tracker' );
	await page.getByRole( 'button', { name: 'Analyse page' } ).click();
	await expect( page.getByTestId( 'onpage-score' ) ).toBeVisible();
	await expect( page.getByTestId( 'score-presentation' ) ).toContainText(
		'Content checked. Needs a published-page check to score.'
	);
	await expect( page.getByTestId( 'seo-light' ) ).toHaveText(
		'SEO: Not verified'
	);
	await expect( page.getByTestId( 'seo-light' ) ).toHaveAttribute(
		'data-rating',
		'grey'
	);
	await expect( page.getByTestId( 'readability-light' ) ).toContainText(
		'Content check'
	);
	await expect(
		page.getByRole( 'button', { name: 'Check published page' } ).first()
	).toBeVisible();
	// Analysis-only mode must not present the title as something Update will print.
	await expect( page.getByTestId( 'metadata-note' ) ).toContainText(
		'not printing tags'
	);
	// Analyse does not write SEO fields. The native save stores the phrase for the next load.
	await page.waitForFunction( () => {
		const select = (
			window as unknown as {
				wp: {
					data: {
						select: ( store: string ) => {
							getEditedPostAttribute: ( key: string ) => {
								_seostudio_focus_keyphrase?: string;
							};
						};
					};
				};
			}
		 ).wp.data.select( 'core/editor' );
		return (
			select.getEditedPostAttribute( 'meta' )
				?._seostudio_focus_keyphrase === 'rank tracker'
		);
	} );
	await saveDraft( page );
} );

test( 'pages and the workspace withhold a verified SEO light', async ( {
	page,
} ) => {
	const postId = new URL(
		editedPostUrl,
		'http://example.test'
	).searchParams.get( 'post' );
	expect( postId ).toBeTruthy();
	await page.goto( '/wp-admin/admin.php?page=seostudio&section=pages', {
		waitUntil: 'domcontentloaded',
	} );
	await assertSignedIn( page );
	const table = page.getByRole( 'table' );
	await expect( table ).toContainText(
		'Content checked. Needs a published-page check to score.'
	);
	await expect( table ).toContainText( 'SEO: Not verified' );
	await expect( table ).toContainText( 'Content check' );
	await expect( table ).not.toContainText( 'SEO: Good' );
	const checked = page
		.getByRole( 'row' )
		.filter( {
			hasText: 'Content checked. Needs a published-page check to score.',
		} )
		.first();
	await checked.getByRole( 'link', { name: 'Open analysis' } ).click();
	await page.waitForURL( /view=page&post=\d+/ );
	await expect(
		page.getByText(
			'Content checked. Needs a published-page check to score.',
			{ exact: true }
		)
	).toBeVisible();
	await expect( page.getByTestId( 'seo-light' ) ).toHaveText(
		'SEO: Not verified'
	);
	await expect( page.getByTestId( 'seo-light' ) ).toHaveAttribute(
		'data-rating',
		'grey'
	);
	await expect( page.getByTestId( 'readability-light' ) ).toContainText(
		'Content check'
	);
	await expect( page.locator( '.seostudio-pill' ) ).toHaveCount( 0 );
	await expect(
		page.getByRole( 'button', { name: 'Check published page' } ).first()
	).toBeVisible();
} );

test( 'the classic editor withholds a verified SEO light after a content check', async ( {
	page,
} ) => {
	await page.goto( '/wp-admin/post-new.php', {
		waitUntil: 'domcontentloaded',
	} );
	await assertSignedIn( page );
	await page.waitForFunction(
		() =>
			!! (
				window as unknown as {
					wp?: {
						apiFetch?: ( options: unknown ) => Promise< unknown >;
					};
				}
			 ).wp?.apiFetch
	);
	const postId = await page.evaluate( async () => {
		const created = await (
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
					} ) => Promise< { id: number } >;
				};
			}
		 ).wp.apiFetch( {
			path: '/wp/v2/posts',
			method: 'POST',
			data: {
				title: 'Classic note',
				content: '<p>Body copy for the page.</p>',
				status: 'draft',
			},
		} );
		return created.id;
	} );
	await page.goto(
		`/wp-admin/post.php?post=${ postId }&action=edit&seostudio_editor=classic`,
		{ waitUntil: 'domcontentloaded' }
	);
	await expect( page.locator( '#seostudio-metabox' ) ).toBeVisible();
	await expect( page.locator( '.block-editor' ) ).toHaveCount( 0 );
	await page
		.getByLabel( 'Focus keyphrase', { exact: true } )
		.fill( 'rank tracker' );
	await page.getByRole( 'button', { name: 'Analyse page' } ).click();
	await expect( page.getByTestId( 'score-presentation' ) ).toContainText(
		'Content checked. Needs a published-page check to score.'
	);
	await expect( page.getByTestId( 'seo-light' ) ).toHaveText(
		'SEO: Not verified'
	);
	await expect( page.getByTestId( 'readability-light' ) ).toContainText(
		'Content check'
	);
	await expect(
		page.getByRole( 'button', { name: 'Check published page' } ).first()
	).toBeVisible();
} );

test( 'editing the title updates the title assessment without a reload', async ( {
	page,
} ) => {
	const errors = watchPageErrors( page );
	await openEditor( page, editedPostUrl );
	await openSeoSidebar( page, errors );
	const checks = page.locator( 'details[data-section="checks"]' );
	await checks.locator( '> summary' ).click();
	await checks
		.locator( '.seostudio-check-group > summary' )
		.filter( { hasText: 'SEO title' } )
		.click();
	const titleCheck = checks.locator(
		'details[data-assessment="title.entire_keyphrase"]'
	);
	await titleCheck.locator( 'summary' ).click();
	await expect(
		titleCheck.getByText(
			'This check did not run because the value was not observed.'
		)
	).toBeVisible();
	// Analysis-only mode has not observed the published title. Editing the post
	// title must not make Zoop treat that text as the published title.
	const canvas = page.frameLocator( 'iframe[name="editor-canvas"]' );
	await canvas.getByLabel( 'Add title' ).fill( 'rank tracker welcome' );
	await expect( canvas.getByText( 'rank tracker welcome' ) ).toBeVisible();
	await expect(
		titleCheck.getByText(
			'This check did not run because the value was not observed.'
		)
	).toBeVisible();
	await expect(
		page.getByText( 'The full keyphrase is in the title.' )
	).toHaveCount( 0 );
} );

test( 're-analyse all queues an audit', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=seostudio', {
		waitUntil: 'domcontentloaded',
	} );
	await assertSignedIn( page );
	await page
		.getByRole( 'button', { name: 'Refresh content analysis' } )
		.click();
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
	const saved = page.waitForResponse( ( response ) => {
		const url = decodeURIComponent( response.url() );
		return (
			url.includes( 'seostudio/v1/settings' ) &&
			response.request().method() !== 'GET'
		);
	} );
	await page.getByRole( 'button', { name: 'Save settings' } ).click();
	expect( ( await saved ).ok() ).toBe( true );
	await expect(
		page.locator( '#seostudio-dashboard' ).getByText( 'Settings saved.' )
	).toBeVisible();
}

test( 'owner mode prints the home description', async ( { page } ) => {
	const settingsUrl = '/wp-admin/admin.php?page=seostudio';
	try {
		await page.goto( settingsUrl, { waitUntil: 'domcontentloaded' } );
		await assertSignedIn( page );
		await page
			.getByRole( 'button', { name: 'Settings', exact: true } )
			.click();
		const settings = page.locator( '.seostudio-settings' );
		await expect( settings.locator( '.seostudio-version' ) ).toHaveText(
			versionLabel()
		);
		await settings.locator( 'select' ).first().selectOption( 'owner' );
		await settings
			.getByLabel( 'Home description' )
			.fill( 'Studio home description' );
		await saveSettings( page );
		await page.goto( '/' );
		await expect(
			page.locator(
				'meta[name="description"][content="Studio home description"]'
			)
		).toHaveCount( 1 );
	} finally {
		await page.goto( settingsUrl );
		await page.waitForFunction(
			() =>
				!! (
					window as unknown as {
						wp?: {
							apiFetch?: (
								options: unknown
							) => Promise< unknown >;
						};
					}
				 ).wp?.apiFetch
		);
		await page.evaluate( () => {
			const api = (
				window as unknown as {
					wp: {
						apiFetch: ( options: {
							path: string;
							method: string;
							data: { mode: string };
						} ) => Promise< unknown >;
					};
				}
			 ).wp.apiFetch;
			return api( {
				path: '/seostudio/v1/settings',
				method: 'PUT',
				data: { mode: 'analysis' },
			} );
		} );
	}
} );
