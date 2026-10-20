import path from 'node:path';
import { defineConfig, devices } from '@playwright/test';

const authDir = path.join( __dirname, 'tests/e2e/.auth' );

function browserProject(
	name: 'chromium' | 'webkit',
	device: ( typeof devices )[ 'Desktop Chrome' ]
) {
	return {
		name,
		dependencies: [ `setup-${ name }` ],
		testMatch: /.*\.spec\.ts/,
		use: {
			...device,
			storageState: path.join( authDir, `${ name }.json` ),
		},
	};
}

export default defineConfig( {
	testDir: 'tests/e2e',
	// Each browser logs in once, in its own setup project, before its tests. The saved
	// session files stay separate so the projects do not share a cookie jar.
	workers: 1,
	timeout: 120000,
	expect: { timeout: 20000 },
	fullyParallel: false,
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
	},
	projects: [
		{
			name: 'setup-chromium',
			testMatch: /auth\.setup\.ts/,
			use: { ...devices[ 'Desktop Chrome' ] },
		},
		{
			name: 'setup-webkit',
			testMatch: /auth\.setup\.ts/,
			use: { ...devices[ 'Desktop Safari' ] },
		},
		browserProject( 'chromium', devices[ 'Desktop Chrome' ] ),
		browserProject( 'webkit', devices[ 'Desktop Safari' ] ),
	],
} );
