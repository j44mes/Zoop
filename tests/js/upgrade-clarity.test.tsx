import { renderToStaticMarkup } from 'react-dom/server';
import { ContentResults } from '../../assets/src/analysis/results';
import { UpgradeNoticeBanner } from '../../assets/src/dashboard/upgrade-notice';
import { EvidenceLights } from '../../assets/src/shared/ui/EvidenceLights';
import { TrafficLight } from '../../assets/src/shared/ui/TrafficLight';

const analysis = {
	seo: {
		score: 80,
		rating: 'green' as const,
		assessments: [],
	},
	readability: {
		score: 70,
		rating: 'green' as const,
		assessments: [],
	},
	inclusive: [],
	related: [],
};

describe( 'upgrade clarity presentation', () => {
	it( 'keeps a named traffic light and can force a neutral value', () => {
		expect(
			renderToStaticMarkup(
				<TrafficLight rating="green" label="SEO" testId="seo-light" />
			)
		).toContain( 'Good' );
		const neutral = renderToStaticMarkup(
			<TrafficLight
				rating="green"
				label="SEO"
				value="Not verified"
				testId="seo-light"
			/>
		);
		expect( neutral ).toContain( 'SEO: Not verified' );
		expect( neutral ).toContain( 'data-rating="grey"' );
		expect( neutral ).not.toContain( 'Good' );
	} );

	it( 'does not leave a green SEO light beside an unverified on-page result', () => {
		const withheld = renderToStaticMarkup(
			<EvidenceLights
				verified={ false }
				seoRating="green"
				readabilityRating="green"
				testIds
			/>
		);
		expect( withheld ).toContain( 'SEO: Not verified' );
		expect( withheld ).toContain( 'data-rating="grey"' );
		expect( withheld ).toContain( 'Readability: Good (Content check)' );
		expect( withheld ).not.toContain( 'SEO: Good' );

		const verified = renderToStaticMarkup(
			<EvidenceLights
				verified
				seoRating="green"
				readabilityRating="orange"
				testIds
			/>
		);
		expect( verified ).toContain( 'SEO: Good' );
		expect( verified ).toContain( 'Readability: Ok' );
		expect( verified ).not.toContain( 'Content check' );
		expect( verified ).not.toContain( 'Not verified' );
	} );

	it( 'uses the same lights for a content analysis that has not verified the published page', () => {
		const html = renderToStaticMarkup(
			<ContentResults analysis={ analysis } unavailable={ false } />
		);
		expect( html ).toContain( 'SEO: Not verified' );
		expect( html ).toContain( 'Content check' );
		expect( html ).not.toContain( 'SEO: Good' );

		const ready = renderToStaticMarkup(
			<ContentResults
				analysis={ analysis }
				unavailable={ false }
				onPageVerified
			/>
		);
		expect( ready ).toContain( 'SEO: Good' );
		expect( ready ).not.toContain( 'Not verified' );
	} );

	it( 'links the ruleset notice to its own review list', () => {
		let dismissed = false;
		const html = renderToStaticMarkup(
			<UpgradeNoticeBanner
				notice={ {
					ruleset: 1,
					count: 2,
					message:
						'Zoop 0.6 uses updated scoring rules, so 2 pages need checking again. Your previous scores have been kept.',
					review_url:
						'admin.php?page=seostudio&section=pages&score_state=ruleset_retired',
				} }
				onDismiss={ () => {
					dismissed = true;
				} }
			/>
		);
		expect( html ).toContain( 'role="status"' );
		expect( html ).toContain( '2 pages need checking again' );
		expect( html ).toContain( 'Your previous scores have been kept' );
		expect( html ).toContain( 'score_state=ruleset_retired' );
		expect( html ).not.toContain( 'score_state=needs_reanalysis' );
		expect( html ).toContain( 'Review pages needing checks' );
		expect( html ).toContain( 'Dismiss' );
		expect( dismissed ).toBe( false );
	} );
} );
