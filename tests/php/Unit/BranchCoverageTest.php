<?php
/**
 * Remaining scoring and metadata branches.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Content\AnalysisContext;
use SEOStudio\Content\MetadataResolver;
use SEOStudio\Content\PageModel;
use SEOStudio\Content\TemplateValues;
use SEOStudio\Scoring\OnPage\Advisories;
use SEOStudio\Scoring\OnPage\Normaliser;
use SEOStudio\Scoring\OnPage\Scorer;

/**
 * Each assertion names a branch the fixture set does not reach.
 */
final class BranchCoverageTest extends TestCase {
	public function testAnalysisWithoutACompetitorUsesThePostFallbacks(): void {
		$resolved = ( new MetadataResolver() )->resolve(
			new AnalysisContext(
				'analysis',
				false,
				'Post title',
				'Excerpt',
				'https://example.test/post',
				new TemplateValues( 'Post title', 'Example', '-', 'Excerpt', '', '' ),
				'Ignored title',
				'Ignored description',
				'https://example.test/ignored',
				null
			)
		);

		$this->assertSame( 'Post title', $resolved['title_tag'] );
		$this->assertSame( 'Excerpt', $resolved['meta_description'] );
		$this->assertSame( 'https://example.test/post', $resolved['canonical'] );
	}

	public function testInputHashIgnoresTheRenderTimestamp(): void {
		$model = PageModel::fromArray(
			array(
				'headings' => array( 'skip', array( 'text' => 'Kept' ) ),
				'images'   => array( 'skip' ),
				'links'    => array( 'skip' ),
				'rendered_at' => '2020-01-01T00:00:00+00:00',
			)
		);
		$later = PageModel::fromArray( array_merge( $model->toArray(), array( 'rendered_at' => '2026-01-01T00:00:00+00:00' ) ) );

		$this->assertSame( array(), $model->images );
		$this->assertSame( array(), $model->links );
		$this->assertSame( 'Kept', $model->headings[0]['text'] );
		$this->assertSame( $model->inputHash(), $later->inputHash() );
	}

	public function testPartialAndPositionBands(): void {
		$scorer = new Scorer();
		$middle = $scorer->score(
			$this->page(
				array(
					'url'              => 'https://example.test/rank-tracker',
					'title_tag'        => 'Welcome rank tracker',
					'meta_description' => 'rank tracker only',
					'h1'               => array( 'Welcome rank tracker' ),
					'body_text'        => 'The rank tracker appears once in this sentence.',
					'images'           => array(
						array( 'src' => '/blank.png', 'alt' => '', 'in_body' => true ),
						array( 'src' => '/chart.png', 'alt' => 'rank chart', 'in_body' => true ),
					),
				)
			),
			'rank tracker'
		);
		$this->assertSame( 4, $this->earned( $middle, 'title.position' ) );
		$this->assertSame( 4, $this->earned( $middle, 'headings.position' ) );
		$this->assertSame( 3, $this->earned( $middle, 'body.mentions' ) );
		$this->assertSame( 2, $this->earned( $middle, 'media.alt' ) );

		$partial = $scorer->score(
			$this->page(
				array(
					'url'              => 'https://example.test/rank-tracker',
					'meta_description' => 'rank tracker only',
				)
			),
			'rank tracker guide'
		);
		$this->assertSame( 3, $this->earned( $partial, 'meta.keyphrase' ) );
		$this->assertSame( 3, $this->earned( $partial, 'url.keyphrase' ) );

		$late = $scorer->score(
			$this->page(
				array(
					'title_tag' => 'Alpha beta gamma delta rank tracker',
					'h1'        => array( 'Alpha beta rank tracker' ),
					'body_text' => 'rank tracker. rank tracker.',
					'url'       => 'https://example.test/',
				)
			),
			'rank tracker'
		);
		$this->assertSame( 2, $this->earned( $late, 'title.position' ) );
		$this->assertSame( 2, $this->earned( $late, 'headings.position' ) );
		$this->assertSame( 5, $this->earned( $late, 'body.mentions' ) );
		$this->assertSame( 5, $this->earned( $late, 'url.keyphrase' ) );

		$words = $scorer->score( $this->page( array( 'body_text' => $this->words( 200 ) ) ), 'rank tracker' );
		$this->assertSame( 6, $this->earned( $words, 'body.word_count' ) );
		$more = $scorer->score( $this->page( array( 'body_text' => $this->words( 400 ) ) ), 'rank tracker' );
		$this->assertSame( 10, $this->earned( $more, 'body.word_count' ) );
	}

	public function testAdvisoriesAndFunctionWordEdges(): void {
		$normaliser = new Normaliser();
		$advisories = new Advisories();
		$page       = $this->page(
			array(
				'title_tag'        => str_repeat( 'M', 60 ),
				'meta_description' => 'Short',
				'body_text'        => 'rank rank rank rank',
				'page_type'        => 'article',
				'headings'         => array(
					array( 'level' => 2, 'text' => 'Other', 'order' => 0 ),
				),
			)
		);

		$items = $advisories->forPage( $page, 'rank', $normaliser );
		$ids   = array_column( $items, 'id' );
		$this->assertContains( 'advisory.density', $ids );
		$this->assertContains( 'advisory.title_width', $ids );
		$this->assertContains( 'advisory.meta_length', $ids );
		$this->assertContains( 'advisory.article_length', $ids );
		$this->assertGreaterThan( 600, $advisories->titlePixels( str_repeat( 'M', 60 ) ) );

		$empty = $normaliser->matchField( 'rank tracker', 'the and' );
		$this->assertSame( array(), $empty->contentWords );
		$this->assertSame( 0.0, $empty->coverage );

		$GLOBALS['seostudio_function_words_filter'] = 'nope';
		$filtered = new Normaliser();
		$this->assertFalse( $filtered->isFunctionWord( 'the' ) );
		unset( $GLOBALS['seostudio_function_words_filter'] );
	}

	/**
	 * @param array<string, mixed> $overrides
	 */
	private function page( array $overrides ): PageModel {
		return PageModel::fromArray(
			array_merge(
				array(
					'url'              => 'https://example.test/page',
					'title_tag'        => 'Rank tracker',
					'meta_description' => str_repeat( 'A', 130 ),
					'h1'               => array( 'Rank tracker' ),
					'body_text'        => $this->words( 600 ),
					'page_type'        => 'post',
					'images'           => array(),
					'headings'         => array(),
				),
				$overrides
			)
		);
	}

	private function words( int $count ): string {
		return trim( str_repeat( 'word ', $count ) );
	}

	private function earned( \SEOStudio\Scoring\OnPage\Score $score, string $id ): int {
		foreach ( $score->assessments as $result ) {
			if ( $id === $result->id ) {
				return $result->earned;
			}
		}

		$this->fail( 'Missing assessment ' . $id );
	}
}
