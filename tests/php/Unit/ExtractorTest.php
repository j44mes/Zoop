<?php
/**
 * Extractor tests.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Content\Extractor;
use SEOStudio\Scoring\OnPage\Scorer;

/**
 * Confirms boilerplate is left out of the body.
 */
final class ExtractorTest extends TestCase {
	public function testKeyphraseInsideNavigationIsNotBodyCopy(): void {
		$html = '<html><head><title>Guide</title></head><body>'
			. '<nav><p>rank tracker</p></nav>'
			. '<main><h1>A quiet page</h1><p>This paragraph does not mention the phrase.</p></main>'
			. '</body></html>';

		$model = ( new Extractor() )->extract(
			$html,
			array(
				'url'         => 'https://example.test/quiet',
				'render_mode' => 'live',
				'is_https'    => true,
			)
		);

		$score = ( new Scorer() )->score( $model, 'rank tracker' );
		$this->assertSame( 'main', $model->contentSelectorUsed );
		foreach ( $score->assessments as $result ) {
			if ( 'body.mentions' === $result->id ) {
				$this->assertSame( 0, $result->earned );
				$this->assertSame( 0, $result->observed['mentions'] );
			}
		}
	}
}
