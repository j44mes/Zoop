<?php
/**
 * Scoring evidence: literal phrases, metadata states, and document H1s.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Content\AnalysisContext;
use SEOStudio\Content\Extractor;
use SEOStudio\Content\ForeignHead;
use SEOStudio\Content\MetadataEvidence;
use SEOStudio\Content\MetadataResolver;
use SEOStudio\Content\PageModel;
use SEOStudio\Content\Renderer;
use SEOStudio\Content\TemplateValues;
use SEOStudio\Integrations\PluginHead;
use SEOStudio\Scoring\OnPage\Normaliser;
use SEOStudio\Scoring\OnPage\Scorer;

/**
 * The evidence contract for on-page scoring.
 */
final class EvidenceFidelityTest extends TestCase {
	public function testLiteralPhraseKeepsFunctionWordsAndCoverageStaysFlexible(): void {
		$normaliser = new Normaliser();
		$phrase     = 'how to price a cleaning contract';
		$exact      = $normaliser->matchField( 'How to price a cleaning contract.', $phrase );
		$reordered  = $normaliser->matchField( 'price cleaning contract', $phrase );
		$living     = $normaliser->matchField( 'The cost of living rose', 'cost of living' );
		$missing_of = $normaliser->matchField( 'The cost living rose', 'cost of living' );

		$this->assertTrue( $exact->entire );
		$this->assertSame( 1, $exact->position );
		$this->assertSame( 1, $exact->entireCount );
		$this->assertFalse( $reordered->entire );
		$this->assertSame( 1.0, $reordered->coverage );
		$this->assertTrue( $reordered->partial );
		$this->assertTrue( $living->entire );
		$this->assertSame( 2, $living->position );
		$this->assertFalse( $missing_of->entire );
		$this->assertSame( 1.0, $missing_of->coverage );
		$this->assertSame( array( 'price', 'cleaning', 'contract' ), $normaliser->contentWords( $phrase ) );
	}

	public function testOwnerCalculatedTitleScoresWithoutClaimingFetchedHtml(): void {
		$saved = $this->scoreOwner( 'Rank tracker guide', 'A rank tracker description.' );
		$empty = $this->scoreOwner( '', '' );

		$this->assertSame( 'calculated', $saved->titleEvidence );
		$this->assertSame( 'calculated', $saved->descriptionEvidence );
		$this->assertSame( 'pass', $this->assessmentStatus( $saved, 'title.entire_keyphrase' ) );
		$this->assertStringContainsString( 'not fetched HTML', $this->explanation( $saved, 'title.entire_keyphrase' ) );
		$this->assertSame( 'not_observed', $empty->titleEvidence );
		$this->assertSame( 'not_checked', $this->assessmentStatus( $empty, 'title.exists' ) );
		$this->assertSame( '', $this->recommendation( $empty, 'title.length' ) );
		$this->assertLessThan( $this->denominator( $saved ), $this->denominator( $empty ) );
	}

	public function testUnresolvedTemplatesAndThirdPartyExcerptStayUnscored(): void {
		$token = $this->scoreOwner( '%%page%% %%primary_category%%', '%%unknown%%' );
		$this->assertSame( '', $token->titleTag );
		$this->assertSame( 'not_observed', $token->titleEvidence );
		$this->assertSame( 'not_checked', $this->assessmentStatus( $token, 'title.entire_keyphrase' ) );

		$head = ( new PluginHead() )->render(
			'yoast',
			array( 'title' => '', 'description' => '' ),
			new TemplateValues( 'Garden tools', 'Example', '-', 'A short excerpt', 'Tools', 'Tagline' ),
			'https://example.test/garden'
		);
		$this->assertSame( '', $head->description );
		$foreign = ( new MetadataResolver() )->evidence(
			new AnalysisContext(
				'analysis',
				true,
				'Garden tools',
				'A short excerpt',
				'https://example.test/garden',
				new TemplateValues( 'Garden tools', 'Example', '-', 'A short excerpt', 'Tools', 'Tagline' ),
				'',
				'',
				'',
				$head
			)
		);
		$this->assertSame( '', $foreign['meta_description'] );
		$this->assertSame( MetadataEvidence::NOT_OBSERVED, $foreign['description_evidence'] );

		$home = ( new MetadataResolver() )->evidence(
			new AnalysisContext(
				'owner',
				false,
				'Post title',
				'Excerpt',
				'https://example.test/',
				new TemplateValues( 'Post title', 'Example', '-', 'Excerpt', '', '' ),
				'Ignored',
				'',
				'',
				null,
				array(
					'title_tag'        => 'Home Example',
					'meta_description' => '%%page%%',
					'canonical'        => 'https://example.test/',
				)
			)
		);
		$this->assertSame( 'Home Example', $home['title_tag'] );
		$this->assertSame( MetadataEvidence::CALCULATED, $home['title_evidence'] );
		$this->assertSame( '', $home['meta_description'] );
		$this->assertSame( MetadataEvidence::NOT_OBSERVED, $home['description_evidence'] );

		$unknown = ( new MetadataResolver() )->evidence(
			new AnalysisContext(
				'analysis',
				false,
				'Post title',
				'Excerpt',
				'https://example.test/post',
				new TemplateValues( 'Post title', 'Example', '-', 'Excerpt', '', '' ),
				'Ignored',
				'Ignored',
				'',
				null
			)
		);
		$this->assertSame( '', $unknown['title_tag'] );
		$this->assertSame( MetadataEvidence::NOT_OBSERVED, $unknown['title_evidence'] );
	}

	public function testLiveHtmlDistinguishesAMissingDescriptionFromAFailedObservation(): void {
		$extractor = new Extractor();
		$missing   = $extractor->extract(
			'<html><head><title>Rank tracker</title></head><body><main><p>Body</p></main></body></html>',
			array(
				'render_mode' => 'live',
				'url'         => 'https://example.test/missing',
			)
		);
		$blocked = $extractor->extract(
			'<main><p>Body</p></main>',
			array( 'render_mode' => 'content' )
		);

		$this->assertSame( 'observed', $missing->titleEvidence );
		$this->assertSame( 'observed_absent', $missing->descriptionEvidence );
		$this->assertSame( 'not_applicable', $this->assessmentStatus( $missing, 'meta.keyphrase' ) );
		$this->assertSame( '', $this->recommendation( $missing, 'meta.keyphrase' ) );
		$this->assertSame( 'not_observed', $blocked->titleEvidence );
		$this->assertSame( 'not_observed', $blocked->descriptionEvidence );
		$this->assertNotSame( 'observed_absent', $blocked->descriptionEvidence );
	}

	public function testLegacyLiveModelsDoNotInventDocumentH1Evidence(): void {
		$legacy = PageModel::fromArray(
			array(
				'render_mode' => 'live',
				'title_tag'   => 'Rank tracker',
				'h1'          => array( 'Inside main' ),
				'headings'    => array(
					array(
						'level' => 1,
						'text'  => 'Inside main',
						'order' => 0,
					),
				),
			)
		);

		$this->assertSame( 'not_observed', $legacy->documentH1Evidence );
		$this->assertSame( array(), $legacy->documentH1 );
		$this->assertSame( 'not_observed', PageModel::fromArray( array( 'title_evidence' => 'guessed' ) )->titleEvidence );
		$this->assertSame( 'not_observed', PageModel::fromArray( array( 'document_h1_evidence' => 'observed' ) )->documentH1Evidence );
		$this->assertSame( 'not_checked', $this->assessmentStatus( $legacy, 'headings.h1_count' ) );
		$this->assertSame( '', $this->recommendation( $legacy, 'headings.h1_count' ) );
	}

	public function testAFetchedDocumentCountsEveryH1AndKeepsContentHeadingsSeparate(): void {
		$model = ( new Extractor() )->extract(
			'<html><body><header><h1>Theme</h1></header><main><h1>Content</h1><h2>Sub</h2></main></body></html>',
			array(
				'render_mode' => 'live',
				'url'         => 'https://example.test/headings',
			)
		);

		$this->assertSame( array( 'Theme', 'Content' ), $model->documentH1 );
		$this->assertSame( array( 'Content' ), $model->h1 );
		$this->assertSame( 'Content', $model->headings[0]['text'] );
		$this->assertSame( 'Sub', $model->headings[1]['text'] );
		$this->assertCount( 2, $model->headings );
		$this->assertSame( 'fail', $this->assessmentStatus( $model, 'headings.h1_count' ) );
		$this->assertSame( 'fail', $this->assessmentStatus( $model, 'headings.subheadings' ) );
	}

	public function testContentOnlyRenderingDoesNotRecommendAnH1Change(): void {
		$model = ( new Extractor() )->extract(
			'<html><body><main><h1>Content</h1><h2>Sub</h2></main></body></html>',
			array( 'render_mode' => 'content' )
		);

		$this->assertSame( 'not_observed', $model->documentH1Evidence );
		$this->assertSame( array( 'Content' ), $model->h1 );
		$this->assertSame( 'not_checked', $this->assessmentStatus( $model, 'headings.h1_count' ) );
		$this->assertSame( '', $this->recommendation( $model, 'headings.h1_count' ) );
		$this->assertSame( 'fail', $this->assessmentStatus( $model, 'headings.subheadings' ) );
	}

	public function testWithheldChecksStayOutsideTheDenominator(): void {
		$missing = ( new Extractor() )->extract(
			'<html><head><title>Rank tracker</title></head><body><main><p>Body.</p></main></body></html>',
			array(
				'render_mode' => 'live',
				'url'         => 'https://example.test/missing-description',
			)
		);
		$empty = ( new Extractor() )->extract(
			'<html><head><title></title></head><body><main><p>Body.</p></main></body></html>',
			array(
				'render_mode' => 'live',
				'url'         => 'https://example.test/empty-title',
			)
		);
		$unseen = $this->scoreOwner( '', '' );

		$this->assertSame( 'not_applicable', $this->assessmentStatus( $missing, 'meta.keyphrase' ) );
		$this->assertSame( 0, $this->findResult( $missing, 'meta.keyphrase' )->earned );
		$this->assertSame( '', $this->recommendation( $missing, 'meta.keyphrase' ) );
		$this->assertSame( 'fail', $this->assessmentStatus( $empty, 'title.exists' ) );
		$this->assertSame( 0, $this->findResult( $empty, 'title.exists' )->earned );
		$this->assertSame( 'not_checked', $this->assessmentStatus( $unseen, 'title.exists' ) );
		$this->assertSame( 0, $this->findResult( $unseen, 'title.exists' )->earned );

		$missing_score = ( new Scorer() )->score( $missing, 'rank tracker' );
		$empty_score   = ( new Scorer() )->score( $empty, 'rank tracker' );
		$unseen_score  = ( new Scorer() )->score( $unseen, 'rank tracker' );
		$this->assertSame( $this->applicableMax( $missing ), $missing_score->denominator );
		$this->assertSame( $this->applicableMax( $empty ), $empty_score->denominator );
		$this->assertSame( $this->applicableMax( $unseen ), $unseen_score->denominator );
		$this->assertLessThan( $empty_score->denominator, $unseen_score->denominator );
		$this->assertSame( 7, $this->findResult( $empty, 'title.exists' )->max );
		$this->assertNotSame( 'not_checked', $this->assessmentStatus( $empty, 'title.exists' ) );
	}

	public function testReferenceFixtureStillScoresNinetyTwo(): void {
		$raw  = file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/onpage/01-wincher-reference.json' );
		$data = json_decode( (string) $raw, true );
		$this->assertIsArray( $data );
		$page = PageModel::fromArray( is_array( $data['page'] ) ? $data['page'] : array() );
		$this->assertSame( 'observed', $page->documentH1Evidence );

		$score = ( new Scorer() )->score( $page, 'rank tracker' );
		$this->assertSame( 92, $score->earned );
		$this->assertSame( 100, $score->denominator );
		$this->assertSame( 2, $score->toArray()['evidence_version'] );
	}

	private function scoreOwner( string $title, string $description ): PageModel {
		return ( new Renderer() )->renderResolved(
			'<html><body><main><h1>Content</h1><p>Body copy for the page.</p></main></body></html>',
			new AnalysisContext(
				'owner',
				false,
				'Post title',
				'Excerpt',
				'https://example.test/page',
				new TemplateValues( 'Post title', 'Example', '-', 'Excerpt', '', '' ),
				$title,
				$description,
				'',
				null
			),
			array(
				'url'         => 'https://example.test/page',
				'render_mode' => 'content',
				'is_https'    => true,
			)
		);
	}

	private function assessmentStatus( PageModel $page, string $id ): string {
		return $this->findResult( $page, $id )->status;
	}

	private function recommendation( PageModel $page, string $id ): string {
		return $this->findResult( $page, $id )->recommendation;
	}

	private function explanation( PageModel $page, string $id ): string {
		return $this->findResult( $page, $id )->explanation;
	}

	private function denominator( PageModel $page ): int {
		return ( new Scorer() )->score( $page, 'rank tracker' )->denominator;
	}

	private function applicableMax( PageModel $page ): int {
		$max = 0;
		foreach ( ( new Scorer() )->score( $page, 'rank tracker' )->assessments as $result ) {
			if ( 'not_checked' === $result->status || 'not_applicable' === $result->status ) {
				continue;
			}
			$max += $result->max;
		}

		return $max;
	}

	private function findResult( PageModel $page, string $id ): \SEOStudio\Scoring\OnPage\AssessmentResult {
		foreach ( ( new Scorer() )->score( $page, 'rank tracker' )->assessments as $result ) {
			if ( $id === $result->id ) {
				return $result;
			}
		}

		$this->fail( 'Missing assessment ' . $id );
	}
}
