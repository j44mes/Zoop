<?php
/**
 * Cannibalisation level tests.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Topics\Overlap;

/**
 * Shared phrases are evidence to review, not proof of harm.
 */
final class OverlapTest extends TestCase {
	public function testSameTargetPhraseIsHighAndDoesNotAlsoBecomeATitleOverlap(): void {
		$result = ( new Overlap() )->assess(
			array(
				$this->page( 1, 'Fire door installation guide', 'fire door installation', 'fire door installation', 'commercial' ),
				$this->page( 2, 'Fire door installation cost', 'fire door installation', 'fire door installation', 'commercial' ),
			)
		);

		$this->assertCount( 1, $result['groups'] );
		$this->assertSame( 'high', $result['groups'][0]['level'] );
		$this->assertSame( 'high', $result['groups'][0]['confidence'] );
		$this->assertStringContainsString( 'not proof', $result['groups'][0]['reason'] );
	}

	public function testSharedTopicWithDifferentIntentIsMedium(): void {
		$result = ( new Overlap() )->assess(
			array(
				$this->page( 1, 'Door safety', 'hmo doors', 'fire safety', 'commercial' ),
				$this->page( 2, 'Door rules', 'regulations', 'fire safety', 'informational' ),
			)
		);

		$this->assertSame( 'medium', $result['groups'][0]['level'] );
		$this->assertSame( 'low', $result['groups'][0]['confidence'] );
		$this->assertStringContainsString( 'not proof', $result['groups'][0]['reason'] );
	}

	public function testThreeSharedTitleWordsAreLowAndOneSharedWordIsInsufficient(): void {
		$low  = ( new Overlap() )->assess(
			array(
				$this->page( 1, 'Fire door installation guide', '', '', '' ),
				$this->page( 2, 'Fire door installation cost', '', '', '' ),
			)
		);
		$thin = ( new Overlap() )->assess(
			array(
				$this->page( 1, 'The guide to ranks', 'rank tracker', 'rank tracker', 'informational' ),
				$this->page( 2, 'The guide to charts', 'chart tools', 'chart tools', 'commercial' ),
			)
		);

		$this->assertSame( 'low', $low['groups'][0]['level'] );
		$this->assertStringContainsString( 'titles share words', $low['groups'][0]['reason'] );
		$this->assertSame( 'insufficient', $thin['groups'][0]['level'] );
		$this->assertStringContainsString( 'not proof of harm', $thin['groups'][0]['reason'] );
	}

	/**
	 * One page in an overlap fixture.
	 *
	 * @return array<string, mixed>
	 */
	private function page( int $id, string $title, string $keyphrase, string $topic, string $intent ): array {
		return array(
			'post_id'   => $id,
			'title'     => $title,
			'keyphrase' => $keyphrase,
			'topic'     => $topic,
			'intent'    => $intent,
		);
	}
}
