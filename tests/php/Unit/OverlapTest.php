<?php
/**
 * Overlap observation tests.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Topics\Overlap;

/**
 * Shared phrases are observations. They are not proof that pages compete.
 */
final class OverlapTest extends TestCase {
	public function testIdenticalPhrasesStayAnObservationWhenPurposesDiffer(): void {
		$result = ( new Overlap() )->assess(
			array(
				$this->page( 1, 'Staff absence insurance for schools', 'staff absence insurance', 'staff absence insurance', 'commercial', 'keyphrase', 'suggested' ),
				$this->page( 2, 'Staff absence insurance for academy trusts', 'staff absence insurance', 'staff absence insurance', 'commercial', 'keyphrase', 'suggested' ),
			)
		);

		$this->assertCount( 1, $result['groups'] );
		$group = $result['groups'][0];
		$this->assertSame( 'investigation', $group['level'] );
		$this->assertSame( 'phrase', $group['observation'] );
		$this->assertSame( 'high', $group['observation_confidence'] );
		$this->assertSame( 'insufficient', $group['conclusion_confidence'] );
		$this->assertNotSame( 'high', $group['level'] );
		$this->assertSame( 'same_observation', $this->signal( $group, 'primary_topic' )['establishes'] );
		$this->assertFalse( $this->signal( $group, 'primary_topic' )['independent'] );
		$this->assertSame( 'supports_investigation', $this->signal( $group, 'search_intent' )['establishes'] );
		$this->assertSame( 'not_established', $this->signal( $group, 'cannibalisation' )['establishes'] );
		$this->assertStringContainsString( 'not prove', $group['reason'] );
	}

	public function testUnknownIntentDoesNotCountAsAgreement(): void {
		$result = ( new Overlap() )->assess(
			array(
				$this->page( 1, 'Schools cover', 'staff absence insurance', 'staff absence insurance', 'commercial', 'keyphrase', 'saved' ),
				$this->page( 2, 'Trusts cover', 'staff absence insurance', 'staff absence insurance', 'unassigned', 'keyphrase', 'unassigned' ),
			)
		);

		$intent = $this->signal( $result['groups'][0], 'search_intent' );
		$this->assertSame( 'not_established', $intent['establishes'] );
		$this->assertStringContainsString( 'Unknown intent is not agreement', $intent['detail'] );
		$this->assertSame( 'insufficient', $result['groups'][0]['conclusion_confidence'] );
	}

	public function testATopicDerivedFromThePhraseIsNotASecondFinding(): void {
		$result = ( new Overlap() )->assess(
			array(
				$this->page( 1, 'Schools', 'staff absence insurance', 'staff absence insurance', 'unassigned', 'keyphrase', 'unassigned' ),
				$this->page( 2, 'Trusts', 'staff absence insurance', 'staff absence insurance', 'unassigned', 'keyphrase', 'unassigned' ),
			)
		);

		$this->assertCount( 1, $result['groups'] );
		$this->assertSame( 'phrase', $result['groups'][0]['observation'] );
		$this->assertFalse( $this->signal( $result['groups'][0], 'primary_topic' )['independent'] );
	}

	public function testConfirmedIntentDoesNotProveCompetition(): void {
		$result = ( new Overlap() )->assess(
			array(
				$this->page( 1, 'Schools', 'school insurance', 'school cover', 'commercial', 'saved', 'saved' ),
				$this->page( 2, 'Trusts', 'trust insurance', 'trust cover', 'commercial', 'saved', 'saved' ),
			)
		);

		$this->assertSame( 'insufficient', $result['groups'][0]['level'] );
		$this->assertSame( 'not_established', $this->signal( $result['groups'][0], 'cannibalisation' )['establishes'] );
		$this->assertStringNotContainsString( 'Priority', $result['groups'][0]['reason'] );
	}

	public function testASavedTopicIsIndependentAndStillNotAConflict(): void {
		$result = ( new Overlap() )->assess(
			array(
				$this->page( 1, 'Schools', 'school insurance', 'staff absence insurance', 'commercial', 'saved', 'saved' ),
				$this->page( 2, 'Trusts', 'trust insurance', 'staff absence insurance', 'informational', 'saved', 'saved' ),
			)
		);

		$group = $result['groups'][0];
		$this->assertSame( 'topic', $group['observation'] );
		$this->assertSame( 'high', $group['observation_confidence'] );
		$this->assertSame( 'insufficient', $group['conclusion_confidence'] );
		$this->assertTrue( $this->signal( $group, 'primary_topic' )['independent'] );
		$this->assertSame( 'not_established', $this->signal( $group, 'search_intent' )['establishes'] );
		$this->assertStringContainsString( 'not prove', $group['reason'] );
	}

	public function testThreeSharedTitleWordsStayAWeakObservation(): void {
		$shared = ( new Overlap() )->assess(
			array(
				$this->page( 1, 'Fire door installation guide', '', '', '' ),
				$this->page( 2, 'Fire door installation cost', '', '', '' ),
			)
		);
		$thin   = ( new Overlap() )->assess(
			array(
				$this->page( 1, 'The guide to ranks', 'rank tracker', 'rank tracker', 'informational' ),
				$this->page( 2, 'The guide to charts', 'chart tools', 'chart tools', 'commercial' ),
			)
		);

		$this->assertSame( 'title', $shared['groups'][0]['observation'] );
		$this->assertSame( 'low', $shared['groups'][0]['observation_confidence'] );
		$this->assertSame( 'insufficient', $shared['groups'][0]['conclusion_confidence'] );
		$this->assertStringContainsString( 'titles share words', $shared['groups'][0]['reason'] );
		$this->assertSame( 'insufficient', $thin['groups'][0]['level'] );
		$this->assertStringContainsString( 'not proof of harm', $thin['groups'][0]['reason'] );
	}

	/**
	 * One evidence row by signal name.
	 *
	 * @param array<string, mixed> $group Overlap group.
	 * @return array<string, mixed>
	 */
	private function signal( array $group, string $signal ): array {
		foreach ( (array) ( $group['evidence'] ?? array() ) as $row ) {
			if ( is_array( $row ) && (string) ( $row['signal'] ?? '' ) === $signal ) {
				return $row;
			}
		}
		$this->fail( 'Missing evidence signal ' . $signal );
	}

	/**
	 * One page in an overlap fixture.
	 *
	 * @return array<string, mixed>
	 */
	private function page( int $id, string $title, string $keyphrase, string $topic, string $intent, string $topic_source = '', string $intent_source = '' ): array {
		return array(
			'post_id'       => $id,
			'title'         => $title,
			'keyphrase'     => $keyphrase,
			'topic'         => $topic,
			'intent'        => $intent,
			'topic_source'  => $topic_source,
			'intent_source' => $intent_source,
		);
	}
}
