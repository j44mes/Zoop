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

	public function testMatchingSavedIntentDoesNotProveCompetition(): void {
		$result = ( new Overlap() )->assess(
			array(
				$this->page( 1, 'Schools cover', 'school insurance', 'staff absence insurance', 'commercial', 'saved', 'saved' ),
				$this->page( 2, 'Trusts cover', 'trust insurance', 'staff absence insurance', 'commercial', 'saved', 'saved' ),
			)
		);

		$group = $result['groups'][0];
		$this->assertSame( 'topic', $group['observation'] );
		$this->assertSame( 'high', $group['observation_confidence'] );
		$this->assertSame( 'investigation', $group['conclusion'] );
		$this->assertSame( 'insufficient', $group['conclusion_confidence'] );
		$this->assertTrue( $this->signal( $group, 'primary_topic' )['independent'] );
		$intent = $this->signal( $group, 'search_intent' );
		$this->assertSame( 'saved', $intent['source'] );
		$this->assertTrue( $intent['independent'] );
		$this->assertSame( 'supports_investigation', $intent['establishes'] );
		$this->assertSame( 'not_established', $this->signal( $group, 'cannibalisation' )['establishes'] );
		$this->assertStringContainsString( 'not prove', $group['reason'] );
		$this->assertStringNotContainsString( 'Both pages', $group['reason'] . ' ' . $intent['detail'] );
	}

	public function testMixedTopicSourcesStayWithThePageThatSuppliedThem(): void {
		$result = ( new Overlap() )->assess(
			array(
				$this->page( 1, 'Schools cover', 'school insurance', 'staff absence insurance', 'commercial', 'saved', 'saved' ),
				$this->page( 2, 'Trusts cover', 'trust cover', 'staff absence insurance', 'informational', 'heading', 'suggested' ),
				$this->page( 3, 'College cover', 'college cover', 'staff absence insurance', 'unassigned', 'title', 'unassigned' ),
			)
		);

		$this->assertCount( 1, $result['groups'] );
		$group = $result['groups'][0];
		$this->assertSame( 'topic', $group['observation'] );
		$this->assertSame( 'medium', $group['observation_confidence'] );
		$this->assertSame( 'insufficient', $group['conclusion_confidence'] );
		$this->assertStringContainsString( 'sources differ', $group['reason'] );
		$this->assertStringNotContainsString( 'Both pages', $group['reason'] );

		$topics = $this->signals( $group, 'primary_topic' );
		$this->assertSame( array( 'editorial', 'heading', 'title' ), array_column( $topics, 'source' ) );
		$this->assertTrue( $topics[0]['independent'] );
		$this->assertSame( array( 1 ), array_column( $topics[0]['pages'], 'post_id' ) );
		$this->assertSame( 'editorial', $topics[0]['pages'][0]['source'] );
		$this->assertTrue( $topics[0]['pages'][0]['confirmed'] );
		$this->assertStringContainsString( 'This page', $topics[0]['detail'] );
		$this->assertStringNotContainsString( 'These pages', $topics[0]['detail'] );
		$this->assertFalse( $topics[1]['independent'] );
		$this->assertSame( array( 2 ), array_column( $topics[1]['pages'], 'post_id' ) );
		$this->assertSame( 'heading', $topics[1]['pages'][0]['source'] );
		$this->assertStringContainsString( 'This page', $topics[1]['detail'] );
		$this->assertFalse( $topics[2]['independent'] );
		$this->assertSame( array( 3 ), array_column( $topics[2]['pages'], 'post_id' ) );
		$this->assertSame( 'title', $topics[2]['pages'][0]['source'] );
		$this->assertSame( 'not_established', $this->signal( $group, 'cannibalisation' )['establishes'] );
		$this->assertSame( array( 1, 2, 3 ), array_column( $group['pages'], 'post_id' ) );
	}

	public function testAPhraseGroupOfThreePagesDoesNotAssumeTwo(): void {
		$result = ( new Overlap() )->assess(
			array(
				$this->page( 1, 'Schools', 'staff absence insurance', 'staff absence insurance', 'commercial', 'keyphrase', 'suggested' ),
				$this->page( 2, 'Trusts', 'staff absence insurance', 'staff absence insurance', 'commercial', 'keyphrase', 'suggested' ),
				$this->page( 3, 'Colleges', 'staff absence insurance', 'staff absence insurance', 'commercial', 'keyphrase', 'suggested' ),
			)
		);

		$this->assertCount( 1, $result['groups'] );
		$group = $result['groups'][0];
		$this->assertSame( 'phrase', $group['observation'] );
		$this->assertSame( 'high', $group['observation_confidence'] );
		$this->assertSame( 'insufficient', $group['conclusion_confidence'] );
		$this->assertSame( array( 1, 2, 3 ), array_column( $group['pages'], 'post_id' ) );
		$text = $group['reason'];
		foreach ( (array) $group['evidence'] as $row ) {
			$text .= ' ' . ( is_array( $row ) ? (string) ( $row['detail'] ?? '' ) : '' );
		}
		$this->assertStringNotContainsString( 'Both pages', $text );
		$this->assertStringContainsString( 'These pages', $text );
		$this->assertSame( 'not_established', $this->signal( $group, 'cannibalisation' )['establishes'] );
	}

	public function testAcceptingAHeadingSuggestionIsNotIndependent(): void {
		$result = ( new Overlap() )->assess(
			array(
				$this->page( 1, 'Fire door services', '', 'fire door installation', 'commercial', 'saved', 'saved', 'Fire door installation' ),
				$this->page( 2, 'Fire door cost', 'installation prices', 'fire door installation', 'informational', 'heading', 'suggested', 'Fire door installation' ),
			)
		);

		$this->assertCount( 1, $result['groups'] );
		$group = $result['groups'][0];
		$this->assertSame( 'topic', $group['observation'] );
		$this->assertSame( 'medium', $group['observation_confidence'] );
		$this->assertSame( 'insufficient', $group['conclusion_confidence'] );
		$topic = $this->signal( $group, 'primary_topic' );
		$this->assertSame( 'heading', $topic['source'] );
		$this->assertFalse( $topic['independent'] );
		$this->assertSame( 'same_observation', $topic['establishes'] );
		$this->assertSame( 'not_established', $this->signal( $group, 'cannibalisation' )['establishes'] );
		$by_id = array();
		foreach ( (array) $topic['pages'] as $listed ) {
			if ( is_array( $listed ) ) {
				$by_id[ (int) $listed['post_id'] ] = $listed;
			}
		}
		$this->assertTrue( $by_id[1]['confirmed'] );
		$this->assertSame( 'heading', $by_id[1]['source'] );
		$this->assertFalse( $by_id[2]['confirmed'] );
		$this->assertStringContainsString( 'not prove', $group['reason'] );
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
		$topic = $this->signal( $group, 'primary_topic' );
		$this->assertSame( 'editorial', $topic['source'] );
		$this->assertTrue( $topic['independent'] );
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
		$rows = $this->signals( $group, $signal );
		if ( array() === $rows ) {
			$this->fail( 'Missing evidence signal ' . $signal );
		}
		return $rows[0];
	}

	/**
	 * Every evidence row with one signal name.
	 *
	 * @param array<string, mixed> $group Overlap group.
	 * @return list<array<string, mixed>>
	 */
	private function signals( array $group, string $signal ): array {
		$rows = array();
		foreach ( (array) ( $group['evidence'] ?? array() ) as $row ) {
			if ( is_array( $row ) && (string) ( $row['signal'] ?? '' ) === $signal ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * One page in an overlap fixture.
	 *
	 * @return array<string, mixed>
	 */
	private function page( int $id, string $title, string $keyphrase, string $topic, string $intent, string $topic_source = '', string $intent_source = '', string $heading = '' ): array {
		return array(
			'post_id'       => $id,
			'title'         => $title,
			'keyphrase'     => $keyphrase,
			'topic'         => $topic,
			'intent'        => $intent,
			'heading'       => $heading,
			'topic_source'  => $topic_source,
			'intent_source' => $intent_source,
		);
	}
}
