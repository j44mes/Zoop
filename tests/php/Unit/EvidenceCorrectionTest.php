<?php
/**
 * Corrections for robots directives, observed absence, and visibility.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Content\PageModel;
use SEOStudio\Scoring\OnPage\CheckPresentation;
use SEOStudio\Scoring\OnPage\ScorePresentation;
use SEOStudio\Scoring\OnPage\Scorer;
use SEOStudio\Storage\Indexability;

/**
 * The headline stays available when absence was observed.
 */
final class EvidenceCorrectionTest extends TestCase {
	public function testRobotsParsingIgnoresParameterValues(): void {
		$this->assertFalse( Indexability::blocksIndex( 'index, max-image-preview:none' ) );
		$this->assertFalse( Indexability::blocksIndex( 'max-image-preview:none' ) );
		$this->assertFalse( Indexability::blocksIndex( 'googlebot: max-image-preview:none' ) );
		$this->assertFalse( Indexability::blocksIndex( 'index, follow' ) );
		$this->assertFalse( Indexability::blocksIndex( '' ) );
		$this->assertTrue( Indexability::blocksIndex( 'noindex' ) );
		$this->assertTrue( Indexability::blocksIndex( 'none' ) );
		$this->assertTrue( Indexability::blocksIndex( 'noindex,nofollow' ) );
		$this->assertTrue( Indexability::blocksIndex( 'googlebot: noindex' ) );
		$this->assertTrue( Indexability::blocksIndex( 'Googlebot: Noindex' ) );
		$this->assertTrue( Indexability::blocksIndex( 'index, noindex' ) );
		$this->assertTrue( Indexability::blocksIndex( 'noindex, index' ) );
	}

	public function testAnEmptyObservedTitleAndZeroH1sStayInsideTheScore(): void {
		$absent = $this->page( '', 'observed_absent', array(), 'observed' );
		$empty  = $this->page( '', 'observed', array(), 'observed' );
		$valid  = $this->page( 'Rank tracker guide for editors', 'observed', array( 'Rank tracker guide' ), 'observed' );

		foreach ( array( $absent, $empty ) as $page ) {
			$score = ( new Scorer() )->score( $page, 'rank tracker' );
			$this->assertSame( 'fail', $this->assessmentStatus( $score, 'title.exists' ) );
			$this->assertSame( 'fail', $this->assessmentStatus( $score, 'title.entire_keyphrase' ) );
			$this->assertNotSame( 'not_applicable', $this->assessmentStatus( $score, 'title.partial_keyphrase' ) );
			$this->assertSame( 'fail', $this->assessmentStatus( $score, 'headings.h1_count' ) );
			$this->assertSame( 'not_applicable', $this->assessmentStatus( $score, 'meta.keyphrase' ) );
			$gate = ScorePresentation::gate(
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
			$this->assertGreaterThan( 0, $score->denominator );
			$this->assertLessThan( $score->denominator, $score->earned );
		}

		$ready = ( new Scorer() )->score( $valid, 'rank tracker' );
		$this->assertSame( 'pass', $this->assessmentStatus( $ready, 'title.exists' ) );
		$this->assertNotSame( 'not_applicable', $this->assessmentStatus( $ready, 'title.entire_keyphrase' ) );
		$valid_gate = ScorePresentation::gate(
			$valid,
			$ready,
			'rank tracker',
			array(
				'status' => 'indexable',
				'source' => 'response',
			)
		);
		$this->assertTrue( $valid_gate['ready'] );
		$this->assertSame( '', $valid_gate['missing'] );
	}

	public function testUnobservedTitleAndHeadingEvidenceWithholdTheHeadline(): void {
		$page  = new PageModel(
			titleEvidence: 'not_observed',
			descriptionEvidence: 'not_observed',
			documentH1Evidence: 'not_observed',
			renderMode: 'content'
		);
		$score = ( new Scorer() )->score( $page, 'rank tracker' );
		$this->assertSame( 'not_checked', $this->assessmentStatus( $score, 'title.exists' ) );
		$this->assertSame( 'not_checked', $this->assessmentStatus( $score, 'title.entire_keyphrase' ) );
		$this->assertSame( 'not_checked', $this->assessmentStatus( $score, 'headings.h1_count' ) );
		$gate = ScorePresentation::gate(
			$page,
			$score,
			'rank tracker',
			array(
				'status' => 'unobserved',
				'source' => '',
			)
		);
		$this->assertFalse( $gate['ready'] );
		$this->assertStringContainsString( 'indexability', $gate['missing'] );
		$this->assertStringContainsString( 'title', $gate['missing'] );
		$this->assertStringContainsString( 'h1', $gate['missing'] );
		$this->assertStringContainsString( 'keyphrase', $gate['missing'] );
	}

	public function testAMissingKeyphraseWithholdsTheHeadline(): void {
		$page  = $this->page( 'Rank tracker guide for editors', 'observed', array( 'Rank tracker guide' ), 'observed' );
		$score = ( new Scorer() )->score( $page, '' );
		$gate  = ScorePresentation::gate(
			$page,
			$score,
			'',
			array(
				'status' => 'indexable',
				'source' => 'response',
			)
		);
		$this->assertFalse( $gate['ready'] );
		$this->assertSame( 'keyphrase', $gate['missing'] );
	}

	public function testKnownVisibilityStatusesAreNotMissingIndexability(): void {
		$page  = $this->page( 'Rank tracker guide for editors', 'observed', array( 'Rank tracker guide' ), 'observed' );
		$score = ( new Scorer() )->score( $page, 'rank tracker' );
		foreach ( array( 'indexable', 'noindex', 'not_public', 'site_discouraged' ) as $status ) {
			$gate = ScorePresentation::gate(
				$page,
				$score,
				'rank tracker',
				array(
					'status' => $status,
					'source' => 'visibility',
				)
			);
			$this->assertStringNotContainsString( 'indexability', $gate['missing'] );
			$this->assertTrue( $gate['ready'] );
		}
		$this->assertSame( 'Hidden from search', ScorePresentation::searchLabel( 'noindex' ) );
		$this->assertSame( 'This page is not public', ScorePresentation::searchLabel( 'not_public' ) );
		$this->assertNotSame( 'Hidden from search', ScorePresentation::searchLabel( 'not_public' ) );
		$this->assertNotSame( 'Hidden from search', ScorePresentation::searchLabel( 'site_discouraged' ) );
		$this->assertStringContainsString( 'separate from a noindex', ScorePresentation::searchExplanation( 'not_public', 'visibility' ) );
		$this->assertStringContainsString( 'has not observed a noindex', ScorePresentation::searchExplanation( 'site_discouraged', 'blog_public' ) );
	}

	public function testTheReanalysisSentenceUsesSingularAndPlural(): void {
		$this->assertSame( '1 page has changed or needs checking again.', ScorePresentation::reanalysisNotice( 1 ) );
		$this->assertSame( '2 pages have changed or need checking again.', ScorePresentation::reanalysisNotice( 2 ) );
		$this->assertSame( '0 pages have changed or need checking again.', ScorePresentation::reanalysisNotice( 0 ) );
	}

	public function testAPresentedCheckCarriesTheMeasuredValueAndRecommendation(): void {
		$check = CheckPresentation::fromAssessment(
			array(
				'id'             => 'title.exists',
				'category'       => 'title',
				'status'         => 'fail',
				'explanation'    => 'The page has no title tag.',
				'recommendation' => 'Add a title tag that describes the page.',
				'evidence'       => '',
				'observed'       => array( 'exists' => false ),
			),
			true
		);
		$this->assertSame( 'needs_work', $check['state'] );
		$this->assertSame( 'title', $check['category'] );
		$this->assertSame( 'exists: false', $check['measured'] );
		$this->assertSame( 'Add a title tag that describes the page.', $check['recommendation'] );
		$this->assertSame( 'view_fix', $check['action'] );
	}

	private function page( string $title, string $title_evidence, array $h1, string $h1_evidence ): PageModel {
		return new PageModel(
			titleTag: $title,
			titleEvidence: $title_evidence,
			descriptionEvidence: 'observed_absent',
			documentH1: $h1,
			documentH1Evidence: $h1_evidence,
			renderMode: 'live',
			metadataStatus: 'observed_html',
			bodyText: 'Rank tracker guide ' . str_repeat( 'word ', 80 ),
			wordCount: 82,
			isHttps: true,
			url: 'https://example.test/rank-tracker-guide'
		);
	}

	private function assessmentStatus( \SEOStudio\Scoring\OnPage\Score $score, string $id ): string {
		foreach ( $score->assessments as $assessment ) {
			if ( $id === $assessment->id ) {
				return $assessment->status;
			}
		}
		$this->fail( 'Missing assessment ' . $id );
		return '';
	}
}
