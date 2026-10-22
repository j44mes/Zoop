import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { test, expect } from '@playwright/test';

const shots = process.env.ZOOP_SHOTS || '';

const root = path.resolve( __dirname, '../..' );

function wpEval( mode: 'clear' | 'plant' | 'failure' ): string {
	const file = 'tests/e2e/seed-legacy-notice.php';
	const envPath = process.env.WP_PATH;
	if ( envPath ) {
		return execFileSync(
			'php',
			[
				'/tmp/wp-cli.phar',
				'eval-file',
				file,
				mode,
				`--path=${ envPath }`,
			],
			{ cwd: root, encoding: 'utf8' }
		);
	}

	const plugin = path.basename( root );
	return execFileSync(
		'npx',
		[
			'wp-env',
			'run',
			'cli',
			'wp',
			'eval-file',
			`wp-content/plugins/${ plugin }/tests/e2e/seed-legacy-notice.php`,
			mode,
		],
		{ cwd: root, encoding: 'utf8' }
	);
}

test.describe( 'saved-score upgrade notice', () => {
	test( 'a fresh site hides it, and a 0.5.0-style score explains the review', async ( {
		page,
	} ) => {
		wpEval( 'clear' );
		await page.goto( '/wp-admin/admin.php?page=seostudio' );
		await expect( page.getByTestId( 'legacy-score-notice' ) ).toHaveCount(
			0
		);

		const planted = wpEval( 'plant' );
		const post = planted.match( /ZOOP_POST=(\d+)/ );
		await page.goto( '/wp-admin/admin.php?page=seostudio' );
		const notice = page.getByTestId( 'legacy-score-notice' );
		await expect( notice ).toContainText(
			'Your previous scores have been kept but are no longer shown'
		);
		await expect( notice ).toContainText(
			'1 page needs a new published-page check'
		);
		if ( shots ) {
			fs.mkdirSync( shots, { recursive: true } );
			await page.screenshot( {
				path: path.join( shots, 'upgrade-notice.png' ),
				fullPage: true,
			} );
		}
		await notice.getByRole( 'link', { name: 'Review pages' } ).click();
		await expect( page ).toHaveURL( /score_state=legacy_scores/ );
		await page.goto( '/wp-admin/admin.php?page=seostudio' );
		await page
			.getByTestId( 'legacy-score-notice' )
			.getByRole( 'button', { name: 'Dismiss' } )
			.click();
		await expect( page.getByTestId( 'legacy-score-notice' ) ).toHaveCount(
			0
		);
		await page.reload();
		await expect( page.getByTestId( 'legacy-score-notice' ) ).toHaveCount(
			0
		);

		wpEval( 'failure' );
		await page.goto(
			`/wp-admin/admin.php?page=seostudio&view=page&post=${
				post ? post[ 1 ] : '0'
			}`
		);
		const failure = page.locator( '#seostudio-dashboard' );
		await expect( failure ).toContainText(
			"Couldn't check the published page. Your host returned a protection page (HTTP 202). Password protection or a bot check may be blocking Zoop."
		);
		await expect( failure ).toContainText( 'Last checked successfully on' );
		await expect( failure ).toContainText(
			'failed, so Zoop is still showing the earlier result.'
		);
		await expect( failure ).not.toContainText( 'protection_page' );
		await expect(
			failure.getByRole( 'button', { name: 'Try again' } )
		).toBeVisible();
		await expect(
			failure.getByRole( 'link', { name: 'How to let Zoop through' } )
		).toHaveAttribute( 'href', /letting-zoop-through\.md$/ );
		if ( shots ) {
			await page.screenshot( {
				path: path.join( shots, 'fetch-failure.png' ),
				fullPage: true,
			} );
		}
	} );
} );
