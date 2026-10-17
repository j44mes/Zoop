<?php
/**
 * Builder adapter tests.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Integrations\Builders\DiviAdapter;
use SEOStudio\Integrations\Builders\ElementorAdapter;
use SEOStudio\Integrations\Builders\GutenbergAdapter;

/**
 * Adapters report how content is reached. They do not require the builder plugins.
 */
final class BuilderAdapterTest extends TestCase {
	public function testGutenbergBlocksAreDetectedFromComments(): void {
		$report = ( new GutenbergAdapter() )->describe( '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' );
		$this->assertTrue( $report['detected'] );
		$this->assertSame( 'sidebar', $report['panel'] );
	}

	public function testDivi4ShortcodesAndDivi5BlocksAreDistinguished(): void {
		$legacy = ( new DiviAdapter() )->describe( '[et_pb_text]Hello[/et_pb_text]' );
		$blocks = ( new DiviAdapter() )->describe( '<!-- wp:divi/text --><!-- /wp:divi/text -->' );
		$this->assertSame( '4', $legacy['version'] );
		$this->assertSame( '5', $blocks['version'] );
		$this->assertSame( 'metabox', $blocks['panel'] );
	}

	public function testElementorDataMeansTheFlattenedCopyIsWhatContentRenderSees(): void {
		$report = ( new ElementorAdapter() )->describe( '<p>Flattened</p>', '[{"elType":"section"}]' );
		$this->assertTrue( $report['detected'] );
		$this->assertSame( 'flattened_post_content', $report['reachable'] );
		$this->assertFalse( ( new ElementorAdapter() )->describe( '<p>Plain</p>' )['detected'] );
	}
}
