<?php
/**
 * Hidden content and selector tests.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Content\Extractor;

/**
 * Hidden markup and custom selectors must not leak into the body.
 */
final class ExtractorHiddenTest extends TestCase {
	public function testHiddenContentIsExcluded(): void {
		$html  = '<html><body><main>'
			. '<p style="display: none">rank tracker</p>'
			. '<p style="visibility: hidden">rank tracker</p>'
			. '<p hidden>rank tracker</p>'
			. '<p aria-hidden="true">rank tracker</p>'
			. '<p>Visible sentence.</p>'
			. '</main></body></html>';
		$model = ( new Extractor() )->extract( $html, array( 'url' => 'https://example.test/hidden', 'render_mode' => 'live' ) );

		$this->assertStringNotContainsString( 'rank', $model->bodyText );
		$this->assertStringContainsString( 'Visible sentence.', $model->bodyText );
	}

	public function testCustomSelectorIsHonouredInBothRenderModes(): void {
		$html = '<html><body>'
			. '<div class="noise"><p>rank tracker</p></div>'
			. '<article class="entry"><p>Clean words here.</p></article>'
			. '</body></html>';

		foreach ( array( 'content', 'live' ) as $mode ) {
			$model = ( new Extractor() )->extract(
				$html,
				array(
					'url'              => 'https://example.test/selector',
					'render_mode'      => $mode,
					'content_selector' => '.entry',
				)
			);
			$this->assertSame( '.entry', $model->contentSelectorUsed );
			$this->assertStringNotContainsString( 'rank', $model->bodyText );
			$this->assertStringContainsString( 'Clean words here.', $model->bodyText );
		}
	}
}
