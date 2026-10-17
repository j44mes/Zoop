import { defineConfig, devices } from '@playwright/test';

export default defineConfig( {
	testDir: 'tests/e2e',
	timeout: 120000,
	expect: { timeout: 20000 },
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
