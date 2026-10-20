import fs from 'node:fs';
import path from 'node:path';
import { expect, test as setup } from '@playwright/test';

/**
 * One HTTP login per browser. The cookie jar is saved and reused by that
 * browser's tests. There is no form fill and no retry.
 */
setup( 'store the admin session', async ( { page }, testInfo ) => {
	const browser = testInfo.project.name.replace( /^setup-/, '' );
	const authFile = path.join( __dirname, '.auth', `${ browser }.json` );
	fs.mkdirSync( path.dirname( authFile ), { recursive: true } );

	const baseURL = testInfo.project.use.baseURL || 'http://localhost:8888';
	await page.context().addCookies( [
		{
			name: 'wordpress_test_cookie',
			value: 'WP Cookie check',
			url: baseURL,
		},
	] );

	const login = await page.request.post( '/wp-login.php', {
		form: {
			log: 'admin',
			pwd: 'password',
			'wp-submit': 'Log In',
			redirect_to: new URL( '/wp-admin/profile.php', baseURL ).toString(),
			testcookie: '1',
		},
		maxRedirects: 0,
	} );
	const loggedIn = ( await page.context().cookies() ).some( ( cookie ) =>
		cookie.name.startsWith( 'wordpress_logged_in_' )
	);
	expect(
		loggedIn,
		`authentication: login returned ${ login.status() } without a WordPress logged-in cookie`
	).toBe( true );

	const profile = await page.request.get( '/wp-admin/profile.php', {
		maxRedirects: 0,
	} );
	expect(
		profile.status(),
		'authentication: profile.php did not open for the stored session'
	).toBe( 200 );
	expect(
		await profile.text(),
		'authentication: profile.php did not render the WordPress account screen'
	).toContain( 'profile-php' );

	await page.context().storageState( { path: authFile } );
} );
