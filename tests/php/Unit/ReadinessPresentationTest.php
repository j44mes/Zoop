<?php
/**
 * Presentation labels and the evidence gate.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Content\PageModel;
use SEOStudio\Content\SourceFingerprint;
use SEOStudio\Scoring\OnPage\CheckPresentation;
use SEOStudio\Scoring\OnPage\ScorePresentation;
use SEOStudio\Scoring\OnPage\Scorer;

/**
 * PHP owns the headline, the check states, and the X/Y counts.
 */
final class ReadinessPresentationTest extends TestCase {
	public function testGateAndLabelsCoverEveryVisibleState(): void {
		$page  = new PageModel(
			titleTag: 'Rank tracker guide',
			titleEvidence: 'observed',
			documentH1: array( 'Rank tracker guide' ),
			documentH1Evidence: 'observed',
			renderMode: 'live',
			metadataStatus: 'observed_html',
			bodyText: 'Rank tracker guide ' . str_repeat( 'word ', 80 ),
			wordCount: 82,
			isHttps: true,
			url: 'https://example.test/rank-tracker-guide'
		);
		$score = ( new Scorer() )->score( $page, 'rank tracker' );
		$gate  = ScorePresentation::gate(
			$page,
			$score,
			'rank tracker',
			array(
				'status' => 'indexable',
				'source' => 'response',
			)
		);
		$this->assertTrue( $gate['ready'] );
		$this->assertSame( '', $gate['missing'] );
		$this->assertGreaterThan( 0, $gate['checks_evaluated'] );
		$this->assertGreaterThanOrEqual( $gate['checks_evaluated'], $gate['checks_applicable'] );

		$row = ScorePresentation::fromRow(
			array(
				'onpage_state'           => 'current',
				'evidence_ready'         => 1,
				'onpage_score'           => 72,
				'onpage_denominator'     => 94,
				'checks_evaluated'       => $gate['checks_evaluated'],
				'checks_applicable'      => $gate['checks_applicable'],
				'missing_evidence'       => '',
				'has_published_snapshot' => 1,
				'verified_at'            => '2026-10-20 05:40:00',
				'index_status'           => 'noindex',
				'index_source'           => 'http',
				'onpage_rating'          => 'green',
			)
		);
		$this->assertTrue( $row['definitive'] );
		$this->assertSame( '72 / 94', $row['headline'] );
		$this->assertStringContainsString( 'Based on', $row['explanation'] );
		$this->assertSame( 'Hidden from search', $row['search_label'] );
		$this->assertStringContainsString( 'X-Robots-Tag', $row['search_explanation'] );
		$this->assertStringContainsString( 'last verified', $row['verified_label'] );

		$withheld = ScorePresentation::gate(
			new PageModel( titleEvidence: 'calculated', documentH1Evidence: 'not_observed', renderMode: 'content' ),
			$score,
			'',
			array(
				'status' => 'unobserved',
				'source' => '',
			)
		);
		$this->assertFalse( $withheld['ready'] );
		$this->assertStringContainsString( 'title', $withheld['missing'] );
		$this->assertStringContainsString( 'h1', $withheld['missing'] );
		$this->assertStringContainsString( 'keyphrase', $withheld['missing'] );
		$items = ScorePresentation::missingItems( $withheld['missing'] );
		$this->assertNotEmpty( $items );
		$this->assertSame( 'add_keyphrase', $items[ array_key_last( $items ) ]['action'] );

		$states = array(
			'pass'            => 'passed',
			'fail'            => 'needs_work',
			'partial'         => 'needs_work',
			'not_checked'     => 'not_checked',
			'not_applicable'  => 'not_applicable',
		);
		foreach ( $states as $status => $expected ) {
			$check = CheckPresentation::fromAssessment(
				array(
					'id'          => 'title.exists',
					'status'      => $status,
					'explanation' => 'not_checked' === $status ? 'The keyphrase was not provided.' : 'Measured.',
				),
				true
			);
			$this->assertSame( $expected, $check['state'] );
		}
		$stale = CheckPresentation::fromAssessment( array( 'id' => 'title.exists', 'status' => 'pass', 'explanation' => 'Old.' ), false );
		$this->assertSame( 'out_of_date', $stale['state'] );
		$this->assertSame( 'Out of date', ScorePresentation::fromRow( array( 'onpage_state' => 'needs_reanalysis', 'has_published_snapshot' => 1, 'missing_evidence' => 'ruleset' ) )['headline'] );
		$ruleset = ScorePresentation::missingItems( 'ruleset' );
		$this->assertSame( 'Scores need checking against the current rules.', $ruleset[0]['label'] );
		$this->assertSame( 'check_published', $ruleset[0]['action'] );
		$this->assertSame( 'Indexable', ScorePresentation::searchLabel( 'indexable' ) );
		$this->assertSame( 'This page is not public', ScorePresentation::searchLabel( 'not_public' ) );
		$this->assertStringContainsString( 'stay away', ScorePresentation::searchExplanation( 'site_discouraged', '' ) );
	}

	public function testSourceFingerprintChangesWhenTheTitleChanges(): void {
		$GLOBALS['seostudio_post'] = new \WP_Post( 4, 'post', 'Body', 'Rank tracker' );
		$GLOBALS['seostudio_meta'] = array( 4 => array( '_seostudio_focus_keyphrase' => 'rank tracker' ) );
		$first = SourceFingerprint::current( 4 );
		$this->assertNotSame( '', $first );
		$this->assertSame( '', SourceFingerprint::current( 0 ) );
		$GLOBALS['seostudio_post'] = new \WP_Post( 4, 'post', 'Body', 'Changed title' );
		$this->assertNotSame( $first, SourceFingerprint::current( 4 ) );
		$this->assertContains( '_seostudio_title', SourceFingerprint::metaKeys() );
		unset( $GLOBALS['seostudio_post'], $GLOBALS['seostudio_meta'] );
	}
}
