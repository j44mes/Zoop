import fs from 'node:fs';
import path from 'node:path';
import { test as setup } from '@playwright/test';
import { signIn } from './fixtures';

/**
 * One HTTP login per browser. The cookie jar is saved and reused by that
 * browser's tests.
 */
setup( 'store the admin session', async ( { page }, testInfo ) => {
	const browser = testInfo.project.name.replace( /^setup-/, '' );
	const authFile = path.join( __dirname, '.auth', `${ browser }.json` );
	fs.mkdirSync( path.dirname( authFile ), { recursive: true } );
	await signIn( page, 'admin', 'password' );
	await page.context().storageState( { path: authFile } );
} );
