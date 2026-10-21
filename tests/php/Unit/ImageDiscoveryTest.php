<?php
/**
 * Image discovery for a reduced Divi page.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Content\Extractor;
use SEOStudio\Content\PageModel;
use SEOStudio\Scoring\OnPage\Assessments\MediaAlt;
use SEOStudio\Scoring\OnPage\Normaliser;

/**
 * The fixture follows the inspected Linton fire-door page: ten img elements, four in the article.
 * The live site did not return that HTML to this environment, so the fixture is the structure, not a byte copy.
 */
final class ImageDiscoveryTest extends TestCase {
	public function testLiveHtmlSeparatesImagesFromBackgrounds(): void {
		$model  = $this->live();
		$counts = $this->counts( $model );

		$this->assertSame( 4, $counts['html'] );
		$this->assertSame( 6, $counts['template'] );
		$this->assertSame( 1, $counts['css_background'] );
		$this->assertGreaterThanOrEqual( 2, $counts['unverified'] );
		$this->assertNotContains(
			'css_background',
			array_column(
				array_filter(
					$model->images,
					static fn( array $image ): bool => str_contains( $image['src'], 'anti-ligature-door.png' )
				),
				'kind'
			)
		);
	}

	public function testSavedContentMissesTemplateImagesThePublishedPageKeeps(): void {
		$live     = $this->counts( $this->live() );
		$content  = $this->counts( $this->content() );

		$this->assertSame( 4, $content['html'] );
		$this->assertSame( 0, $content['template'] );
		$this->assertSame( 1, $content['css_background'] );
		$this->assertSame( 6, $live['template'] );
		$this->assertGreaterThan( $content['unverified'], $live['unverified'] );
	}

	public function testACssBackgroundDoesNotChangeTheAltScore(): void {
		$with_background = PageModel::fromArray(
			array(
				'images' => array(
					array(
						'src'     => 'https://example.test/hero.jpg',
						'alt'     => '',
						'in_body' => true,
						'kind'    => 'css_background',
						'source'  => 'inline',
					),
				),
			)
		);
		$with_description = PageModel::fromArray(
			array(
				'images' => array(
					array(
						'src'     => 'https://example.test/door.jpg',
						'alt'     => 'rank tracker chart',
						'in_body' => true,
						'kind'    => 'html',
						'source'  => 'src',
					),
					array(
						'src'     => 'https://example.test/hero.jpg',
						'alt'     => 'rank tracker chart',
						'in_body' => true,
						'kind'    => 'css_background',
						'source'  => 'inline',
					),
				),
			)
		);

		$background = ( new MediaAlt() )->evaluate( $with_background, 'rank tracker', new Normaliser() );
		$described  = ( new MediaAlt() )->evaluate( $with_description, 'rank tracker', new Normaliser() );

		$this->assertSame( 0, $background->earned );
		$this->assertSame( 4, $background->max );
		$this->assertSame( 4, $described->earned );
		$this->assertStringNotContainsString( 'includes the keyphrase', $described->recommendation );
		$this->assertStringContainsString( 'optional', $background->recommendation );
	}

	private function live(): PageModel {
		return ( new Extractor() )->extract(
			(string) file_get_contents( $this->fixture() ),
			array(
				'render_mode' => 'live',
				'url'         => 'https://lintonuk.co.uk/our-services/fire-door-installation/',
			)
		);
	}

	private function content(): PageModel {
		$html  = (string) file_get_contents( $this->fixture() );
		$start = strpos( $html, '<!-- content-start -->' );
		$end   = strpos( $html, '<!-- content-end -->' );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$fragment = substr( $html, $start, $end - $start );

		return ( new Extractor() )->extract(
			$fragment,
			array(
				'render_mode' => 'content',
				'url'         => 'https://lintonuk.co.uk/our-services/fire-door-installation/',
			)
		);
	}

	/**
	 * Count each image classification.
	 *
	 * @return array{html: int, template: int, css_background: int, unverified: int}
	 */
	private function counts( PageModel $model ): array {
		$counts = array(
			'html'           => 0,
			'template'       => 0,
			'css_background' => 0,
			'unverified'     => 0,
		);
		foreach ( $model->images as $image ) {
			$kind = (string) ( $image['kind'] ?? '' );
			if ( isset( $counts[ $kind ] ) ) {
				++$counts[ $kind ];
			}
		}
		return $counts;
	}

	private function fixture(): string {
		return dirname( __DIR__, 2 ) . '/fixtures/images/linton-fire-door.html';
	}
}
