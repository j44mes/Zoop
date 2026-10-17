import { defineConfig, devices } from '@playwright/test';

export default defineConfig( {
	testDir: 'tests/e2e',
	// Both browser projects log in as the same WordPress user against one database. Parallel logins
	// race on that user's session_tokens meta and can invalidate each other's cookie (reauth=1).
	workers: 1,
	timeout: 120000,
	expect: { timeout: 20000 },
	// Chromium and WebKit share one wp-env site. Simultaneous logins for the same
	// user drop a session token, and WordPress answers with reauth=1.
	workers: 1,
	fullyParallel: false,
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8888',
		screenshot: 'only-on-failure',
		trace: 'retain-on-failure',
	},
	projects: [
		{ name: 'chromium', use: { ...devices[ 'Desktop Chrome' ] } },
		{ name: 'webkit', use: { ...devices[ 'Desktop Safari' ] } },
	],
} );
