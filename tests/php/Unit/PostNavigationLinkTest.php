<?php
/**
 * Previous and next post links are template links.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Architecture\Graph;
use SEOStudio\Content\Extractor;

/**
 * Twenty Twenty-Five renders post navigation inside the content area.
 */
final class PostNavigationLinkTest extends TestCase {
	public function testRenderedPostNavigationIsTemplateAndEditorialLinksStayContextual(): void {
		$html  = '<html><body><main>'
			. '<p><a href="https://example.test/essay">Read the essay</a></p>'
			. '<p><a href="https://example.test/next-idea" rel="next">Continue reading</a></p>'
			. '<div class="wp-block-group alignwide">'
			. '<nav class="wp-block-group alignwide" aria-label="Post navigation">'
			. '<div class="wp-block-post-navigation-link post-navigation-link-previous">'
			. '<span class="wp-block-post-navigation-link__arrow-previous" aria-hidden="true">←</span>'
			. '<a href="https://example.test/older" rel="prev">Older post</a>'
			. '</div>'
			. '<div class="wp-block-post-navigation-link post-navigation-link-next">'
			. '<a href="https://example.test/newer" rel="next">Newer post</a>'
			. '<span class="wp-block-post-navigation-link__arrow-next" aria-hidden="true">→</span>'
			. '</div>'
			. '</nav></div>'
			. '</main></body></html>';
		$model = ( new Extractor() )->extract( $html, array( 'url' => 'https://example.test/current', 'render_mode' => 'live' ) );
		$kinds = array();
		foreach ( $model->links as $link ) {
			$kinds[ $link['href'] ] = $link['kind'];
		}
		$this->assertSame( 'contextual', $kinds['https://example.test/essay'] );
		$this->assertSame( 'contextual', $kinds['https://example.test/next-idea'] );
		$this->assertSame( 'template', $kinds['https://example.test/older'] );
		$this->assertSame( 'template', $kinds['https://example.test/newer'] );

		$result = ( new Graph() )->evaluate(
			array(
				array(
					'id'       => 'post:1',
					'post_id'  => 1,
					'title'    => 'Current',
					'url'      => 'https://example.test/current',
					'analysed' => true,
					'links'    => $model->links,
				),
				array(
					'id'       => 'post:2',
					'post_id'  => 2,
					'title'    => 'Older post',
					'url'      => 'https://example.test/older',
					'analysed' => true,
					'links'    => array(),
				),
			),
			array(),
			array(
				'home'              => 'https://example.test/',
				'front_id'          => 'post:1',
				'coverage_complete' => true,
				'missing'           => 0,
			)
		);
		$this->assertSame( 'template_only', $result['pages']['post:2']['status'] );
		$this->assertSame( 0, $result['pages']['post:2']['contextual_referrers'] );
		$this->assertSame( 1, $result['pages']['post:2']['template_referrers'] );
		$this->assertNotSame( 'orphan', $result['pages']['post:2']['status'] );
		$this->assertNotSame( 'weak', $result['pages']['post:2']['status'] );
	}
}
