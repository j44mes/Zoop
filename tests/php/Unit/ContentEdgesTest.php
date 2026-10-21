<?php
/**
 * Content branches the main fixtures do not reach.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Content\Extractor;
use SEOStudio\Content\PageModel;

/**
 * Image addresses, hidden text, and the WordPress JSON helper.
 */
final class ContentEdgesTest extends TestCase {
	public function testLazySrcsetAndStyleBackgroundsAreRecorded(): void {
		$html  = '<html><head><link rel="' . 'stylesheet" href="/theme.css">'
			. '<style>.hero{background:url("https://example.test/from-style.jpg")}</style></head><body>'
			. '<div class="et_pb_with_background" style="color:red">Short</div>'
			. '<p aria-hidden="true" style="display:none">Hidden words.</p>'
			. '<main>'
			. '<img alt="lazy" data-src="https://example.test/lazy.jpg">'
			. '<img alt="set" srcset="https://example.test/set.jpg 100w">'
			. '<picture><source srcset="https://example.test/pic.jpg 1x"><img alt="picture"></picture>'
			. '<p>Visible sentence. Another sentence!</p>'
			. '</main></body></html>';
		$model = ( new Extractor() )->extract(
			$html,
			array(
				'render_mode' => 'live',
				'url'         => 'https://example.test/images',
				'robots'      => 'noindex',
			)
		);

		$by_source = array();
		foreach ( $model->images as $image ) {
			$by_source[ $image['source'] ][] = $image;
		}
		$this->assertSame( 'https://example.test/lazy.jpg', $by_source['data-src'][0]['src'] );
		$this->assertSame( 'srcset', $by_source['srcset'][0]['source'] );
		$this->assertSame( 'style', $by_source['style'][0]['source'] );
		$this->assertSame( 'divi', $by_source['divi'][0]['source'] );
		$this->assertSame( 'stylesheet', $by_source['stylesheet'][0]['source'] );
		$this->assertStringContainsString( 'noindex', $model->robots );
		$this->assertNotContains( 'Hidden words.', $model->paragraphs );
	}

	public function testAVeryLargeStyleBlockStaysUnchecked(): void {
		$html    = '<html><body><main><p>Visible sentence for the page.</p>'
			. '<style>' . str_repeat( 'a', 100001 ) . '</style></main></body></html>';
		$model   = ( new Extractor() )->extract( $html, array( 'url' => 'https://example.test/large' ) );
		$sources = array_column( $model->images, 'source' );
		$this->assertContains( 'style', $sources );
		$this->assertNotContains( 'https://example.test/skipped.jpg', array_column( $model->images, 'src' ) );
	}

	public function testInputHashCanUseTheWordPressEncoder(): void {
		$model = PageModel::fromArray( array( 'title_tag' => 'Encoded' ) );
		$this->assertNotSame( '', $model->inputHash() );
	}
}
