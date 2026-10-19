<?php
/**
 * Extractor branch coverage.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Content\Extractor;

/**
 * Selectors, links, images, and live-only fields.
 */
final class ExtractorBranchesTest extends TestCase {
	public function testContentModeUsesTheBodyWhenNoLandmarkExists(): void {
		$model = ( new Extractor() )->extract(
			'<html><body><p>Hello body. Second sentence!</p><p>   </p></body></html>',
			array( 'render_mode' => 'content' )
		);

		$this->assertSame( 'content', $model->contentSelectorUsed );
		$this->assertSame( array( 'Hello body. Second sentence!' ), $model->paragraphs );
		$this->assertCount( 2, $model->sentences );
		$this->assertGreaterThan( 0, $model->wordCount );
	}

	public function testEmptyBodyHasNoWords(): void {
		$model = ( new Extractor() )->extract( '<html><body><main></main></body></html>' );
		$this->assertSame( 0, $model->wordCount );
		$this->assertSame( array(), $model->sentences );
	}

	public function testRoleMainIsUsedWhenNoMainOrArticleExists(): void {
		$model = ( new Extractor() )->extract(
			'<html><body><div role="main"><h1>Role heading</h1><p>Role copy.</p></div></body></html>',
			array( 'render_mode' => 'live', 'url' => 'https://example.test/role' )
		);

		$this->assertSame( '[role=main]', $model->contentSelectorUsed );
		$this->assertSame( 'Role heading', $model->firstH1() );
	}

	public function testSelectorsByIdClassRoleAndTag(): void {
		$html = '<html lang="en-GB"><head>'
			. '<title>Document title</title>'
			. '<meta name="description" content="Document description">'
			. '<meta name="robots" content="noindex">'
			. '<link rel="canonical" href="https://example.test/canon">'
			. '<link rel="stylesheet" href="/theme.css">'
			. '</head><body>'
			. '<header><img src="/banner.png" alt=""><div class="cookie">Cookie banner text that is long enough to look like content.</div></header>'
			. '<nav><div>Navigation text that is also long enough to be skipped as boilerplate.</div></nav>'
			. '<aside id="sidebar"><div>Sidebar text that is long enough to be skipped as boilerplate.</div></aside>'
			. '<div id="entry"><p>Entry copy.</p><a href="">Skip</a><a href="/relative">Relative</a>'
			. '<a href="https://example.test/in">Internal</a>'
			. '<a href="https://other.test/out" rel="nofollow">External</a>'
			. '<img src="/chart.png" alt="Chart"></div>'
			. '<section><p>Section copy.</p></section>'
			. '<div role="main"><p>Role copy inside the document.</p></div>'
			. '<script>rank tracker secret</script><style>.x{}</style><noscript>hidden noscript</noscript>'
			. '<template>template text</template><svg><text>svg</text></svg><iframe>frame</iframe>'
			. '</body></html>';

		$id = ( new Extractor() )->extract( $html, array( 'content_selector' => '#entry', 'url' => 'https://example.test/page' ) );
		$this->assertSame( '#entry', $id->contentSelectorUsed );
		$this->assertStringContainsString( 'Entry copy.', $id->bodyText );
		$this->assertStringNotContainsString( 'secret', $id->bodyText );
		$this->assertCount( 3, $id->links );
		$this->assertSame( 'contextual', $id->links[0]['kind'] );
		$this->assertTrue( $id->links[0]['internal'] );
		$this->assertTrue( $id->links[1]['internal'] );
		$this->assertFalse( $id->links[2]['internal'] );
		$this->assertTrue( $id->links[2]['nofollow'] );

		$missing = ( new Extractor() )->extract( $html, array( 'content_selector' => '.missing' ) );
		$this->assertNotSame( '.missing', $missing->contentSelectorUsed );

		$role = ( new Extractor() )->extract( $html, array( 'content_selector' => '[role=main]' ) );
		$this->assertSame( '[role=main]', $role->contentSelectorUsed );

		$tag = ( new Extractor() )->extract( $html, array( 'content_selector' => 'section' ) );
		$this->assertSame( 'section', $tag->contentSelectorUsed );

		$invalid = ( new Extractor() )->extract( $html, array( 'content_selector' => 'div > p' ) );
		$this->assertNotSame( 'div > p', $invalid->contentSelectorUsed );
	}

	public function testLiveModeReadsHeadFieldsAndSkipsBoilerplate(): void {
		$html = '<html lang="fr"><head>'
			. '<title>Live title</title>'
			. '<meta name="description" content="Live description">'
			. '<meta name="robots" content="nofollow">'
			. '<link rel="canonical" href="https://example.test/live">'
			. '<link rel="stylesheet" href="/theme.css">'
			. '</head><body>'
			. '<header class="banner"><div>Cookie consent banner text that should never become the scored body.</div><img src="/outside.png" alt="Outside"></header>'
			. '<footer><div class="menu">Menu widget popup text that should never become the scored body.</div></footer>'
			. '<div><p>' . str_repeat( 'Visible body copy. ', 8 ) . '</p><img src="/inside.png" alt="Inside"></div>'
			. '</body></html>';

		$model = ( new Extractor() )->extract(
			$html,
			array(
				'render_mode' => 'live',
				'url'         => 'http://example.test/live',
			)
		);

		$this->assertSame( 'density', $model->contentSelectorUsed );
		$this->assertStringContainsString( 'Visible body copy.', $model->bodyText );
		$this->assertStringNotContainsString( 'Cookie consent', $model->bodyText );
		$this->assertSame( 'Live title', $model->titleTag );
		$this->assertSame( 'Live description', $model->metaDescription );
		$this->assertSame( 'nofollow', $model->robots );
		$this->assertSame( 'https://example.test/live', $model->canonical );
		$this->assertSame( 'fr', $model->lang );
		$this->assertFalse( $model->isHttps );
		$this->assertTrue( $model->hasExternalCss );
		$this->assertGreaterThan( 0, $model->htmlBytes );
		$this->assertGreaterThan( 0, $model->visibleTextBytes );
		$inside = array_values( array_filter( $model->images, static fn( array $image ): bool => str_contains( $image['src'], 'inside' ) ) );
		$outside = array_values( array_filter( $model->images, static fn( array $image ): bool => str_contains( $image['src'], 'outside' ) ) );
		$this->assertTrue( $inside[0]['in_body'] );
		$this->assertFalse( $outside[0]['in_body'] );
	}

	public function testALongerBlockWinsADensityTie(): void {
		$short = str_repeat( 'a', 40 );
		$long  = str_repeat( 'a', 80 );
		$html  = '<html><body>'
			. '<div>' . $short . '</div>'
			. '<div>' . $long . '</div>'
			. '</body></html>';

		$model = ( new Extractor() )->extract( $html, array( 'render_mode' => 'live', 'url' => 'https://example.test/dense' ) );
		$this->assertSame( 'density', $model->contentSelectorUsed );
		$this->assertSame( $long, $model->bodyText );
	}
}
