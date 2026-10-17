<?php
/**
 * Metadata resolution tests.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Content\AnalysisContext;
use SEOStudio\Content\ForeignHead;
use SEOStudio\Content\MetadataResolver;
use SEOStudio\Content\Renderer;
use SEOStudio\Content\TemplateValues;
use SEOStudio\Integrations\PluginHead;
use SEOStudio\Scoring\OnPage\Scorer;

/**
 * The scorer must follow the metadata a crawler would receive.
 */
final class MetadataResolverTest extends TestCase {
	public function testSeoTitleMovesTheTitleAssessments(): void {
		$html   = '<html><body><main><h1>A quiet page</h1><p>Nothing to see.</p></main></body></html>';
		$before = $this->score( $html, '' );
		$after  = $this->score( $html, 'rank tracker guide' );

		$this->assertSame( 0, $this->earned( $before, 'title.entire_keyphrase' ) );
		$this->assertSame( 6, $this->earned( $after, 'title.entire_keyphrase' ) );
		$this->assertGreaterThan( $this->earned( $before, 'title.position' ), $this->earned( $after, 'title.position' ) );
	}

	public function testAnalysisOnlyIgnoresUnpublishedSeoStudioFields(): void {
		$values  = $this->values();
		$foreign = new ForeignHead( 'Welcome home', 'About the site', 'https://example.test/' );
		$context = new AnalysisContext(
			'analysis',
			true,
			'A quiet page',
			'Excerpt',
			'https://example.test/quiet',
			$values,
			'rank tracker guide',
			'rank tracker description',
			'https://example.test/rank-tracker',
			$foreign
		);

		$resolved = ( new MetadataResolver() )->resolve( $context );
		$this->assertSame( 'Welcome home', $resolved['title_tag'] );
		$this->assertSame( 'About the site', $resolved['meta_description'] );
		$this->assertSame( 'https://example.test/', $resolved['canonical'] );
	}

	public function testEmptyYoastTitleUsesTheDefaultTemplate(): void {
		$head = ( new PluginHead() )->render(
			'yoast',
			array( 'title' => '' ),
			new TemplateValues( 'Garden tools', 'Example', '-', 'A short excerpt', 'Tools', 'Tagline' ),
			'https://example.test/garden'
		);

		$this->assertSame( 'Garden tools - Example', $head->title );
		$this->assertSame( 'A short excerpt', $head->description );
		$this->assertSame( 'https://example.test/garden', $head->canonical );
	}

	public function testHomepageSettingsOnlyOverridePublishedOwnerMetadata(): void {
		$home = array( 'title_tag' => 'Homepage', 'meta_description' => 'Home description', 'canonical' => 'https://example.test/' );
		foreach ( array( array( 'owner', false, $home ), array( 'owner', true, null ), array( 'analysis', false, null ) ) as $case ) {
			$context = new AnalysisContext( $case[0], $case[1], 'Post title', 'Excerpt', 'https://example.test/page/', $this->values(), 'Unpublished title', '', '', null, $home );
			$resolved = ( new MetadataResolver() )->resolve( $context );
			$this->assertSame( $case[2] ?? array( 'title_tag' => 'Post title', 'meta_description' => 'Excerpt', 'canonical' => 'https://example.test/page/' ), $resolved );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function score( string $html, string $seoTitle ): array {
		$values  = $this->values();
		$context = new AnalysisContext(
			'owner',
			false,
			'A quiet page',
			'',
			'https://example.test/quiet',
			$values,
			$seoTitle,
			'',
			'',
			null
		);
		$model = ( new Renderer() )->renderResolved(
			$html,
			$context,
			array(
				'url'         => 'https://example.test/quiet',
				'render_mode' => 'content',
				'is_https'    => true,
			)
		);

		return ( new Scorer() )->score( $model, 'rank tracker' )->toArray();
	}

	/**
	 * @param array<string, mixed> $score
	 */
	private function earned( array $score, string $id ): int {
		foreach ( $score['assessments'] as $assessment ) {
			if ( $id === $assessment['id'] ) {
				return (int) $assessment['earned'];
			}
		}

		$this->fail( 'Missing assessment ' . $id );
	}

	private function values(): TemplateValues {
		return new TemplateValues( 'A quiet page', 'Example', '–', '', '', '' );
	}
}
