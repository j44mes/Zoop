<?php
/**
 * In-process renderer tests.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Content\AnalysisContext;
use SEOStudio\Content\Renderer;
use SEOStudio\Content\TemplateValues;

/**
 * renderPost needs the WordPress functions stubbed below.
 */
final class RendererTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['seostudio_post'] = new \WP_Post( 7, 'post', '<main><h1>Hello</h1><p>Body copy for the renderer.</p></main>' );
		$GLOBALS['seostudio_permalink'] = 'https://example.test/hello';
		$GLOBALS['seostudio_have_posts'] = true;
		$GLOBALS['seostudio_settings'] = array(
			'mode'                 => 'owner',
			'site_title_separator' => '|',
			'content_selectors'    => array( 'post' => 'section' ),
		);
		$GLOBALS['seostudio_meta'] = array(
			7 => array(
				'_seostudio_title'            => '%%title%% %%category%%',
				'_seostudio_description'      => 'Stored description',
				'_seostudio_canonical'        => '',
				'_seostudio_content_selector' => '.entry',
			),
		);
		$GLOBALS['seostudio_categories'] = array( (object) array( 'name' => 'Garden' ) );
		$GLOBALS['seostudio_has_excerpt'] = true;
		$GLOBALS['seostudio_excerpt'] = 'Excerpt text';
	}

	public function testFromContentMarksTheRenderMode(): void {
		$model = ( new Renderer() )->fromContent( '<p>Plain.</p>', array( 'url' => 'https://example.test/plain' ) );
		$this->assertSame( 'content', $model->renderMode );
		$this->assertStringContainsString( 'Plain.', $model->bodyText );
	}

	public function testRenderResolvedCanScoreLiveHtml(): void {
		$context = new AnalysisContext(
			'analysis',
			false,
			'Post title',
			'Excerpt',
			'https://example.test/live',
			new TemplateValues( 'Post title', 'Example', '-', '', '', '' ),
			'',
			'',
			'',
			null
		);
		$model = ( new Renderer() )->renderResolved(
			'<html><body><main><p>Live copy.</p></main></body></html>',
			$context,
			array(
				'render_mode' => 'live',
				'url'         => 'https://example.test/live',
			)
		);

		$this->assertSame( 'live', $model->renderMode );
		$this->assertSame( 'Post title', $model->titleTag );
		$this->assertSame( 'estimated', $model->metadataStatus );
		$this->assertSame( 'Excerpt', $model->metaDescription );
	}

	public function testRenderPostExpandsOwnerMetadataAndPrefersThePostSelector(): void {
		$GLOBALS['seostudio_post'] = new \WP_Post(
			7,
			'post',
			'<main><p>Main copy.</p></main><div class="entry"><p>Entry copy.</p></div>'
		);

		$model = ( new Renderer() )->renderPost( 7 );
		$this->assertSame( '.entry', $model->contentSelectorUsed );
		$this->assertStringContainsString( 'Entry copy.', $model->bodyText );
		$this->assertStringNotContainsString( 'Main copy.', $model->bodyText );
		$this->assertSame( 'Hello Garden', $model->titleTag );
		$this->assertSame( 'Stored description', $model->metaDescription );
		$this->assertSame( 'https://example.test/hello', $model->canonical );
		$this->assertTrue( $model->isHttps );
		$this->assertSame( 'post', $model->pageType );
	}

	public function testSiteSelectorIsUsedWhenThePostHasNone(): void {
		$GLOBALS['seostudio_meta'][7]['_seostudio_content_selector'] = '';
		$GLOBALS['seostudio_post'] = new \WP_Post( 7, 'post', '<section><p>Section copy.</p></section>' );
		$model = ( new Renderer() )->renderPost( 7 );
		$this->assertSame( 'section', $model->contentSelectorUsed );
		$this->assertStringContainsString( 'Section copy.', $model->bodyText );
	}

	public function testANonArraySelectorMapIsIgnored(): void {
		$GLOBALS['seostudio_meta'][7]['_seostudio_content_selector'] = array( 'nope' );
		$GLOBALS['seostudio_settings']['content_selectors'] = 'nope';
		$GLOBALS['seostudio_categories'] = array();
		$GLOBALS['seostudio_permalink'] = false;
		$GLOBALS['seostudio_has_excerpt'] = false;

		$model = ( new Renderer() )->renderPost( 7 );
		$this->assertSame( '', $model->url );
		$this->assertFalse( $model->isHttps );
		$this->assertSame( 'main', $model->contentSelectorUsed );
	}

	public function testAnEmptyQueryRendersNoContent(): void {
		$GLOBALS['seostudio_have_posts'] = false;
		$model = ( new Renderer() )->renderPost( 7 );
		$this->assertSame( '', $model->bodyText );
	}

	public function testAMissingPostThrows(): void {
		$this->expectException( \RuntimeException::class );
		( new Renderer() )->renderPost( 0 );
	}
}
