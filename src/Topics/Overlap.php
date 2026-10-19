<?php
/**
 * Overlap observations.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Topics;

/**
 * Records shared phrases without turning them into proof that pages compete.
 */
final class Overlap {
	/**
	 * Compare target phrases, topics, and title words.
	 *
	 * @param list<array<string, mixed>> $pages Pages with optional topic and intent sources.
	 * @return array{groups: list<array<string, mixed>>, notes: string[]}
	 */
	public function assess( array $pages ): array {
		$prepared = array();
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$ready = $this->prepare( $page );
			if ( $ready['post_id'] <= 0 ) {
				continue;
			}
			$prepared[] = $ready;
		}

		$groups  = array();
		$covered = array();
		foreach ( $this->buckets( $prepared, 'keyphrase' ) as $phrase => $bucket ) {
			$groups[] = $this->phraseGroup( (string) $phrase, $bucket );
			$this->cover( $covered, $bucket );
		}
		foreach ( $this->buckets( $prepared, 'topic' ) as $phrase => $bucket ) {
			if ( isset( $covered[ implode( ',', $this->ids( $bucket ) ) ] ) || $this->topicRepeatsPhrase( $bucket ) ) {
				continue;
			}
			$groups[] = $this->topicGroup( (string) $phrase, $bucket );
			$this->cover( $covered, $bucket );
		}

		$groups = array_merge( $groups, $this->titleGroups( $prepared, $covered ) );
		if ( array() === $groups ) {
			$groups[] = $this->insufficient( $prepared );
		}

		return array(
			'groups' => array_slice( $groups, 0, 50 ),
			'notes'  => array(
				__( 'A shared target phrase confirms that the phrases match. It does not establish that the pages compete.', 'seo-studio' ),
				__( 'A topic copied from that phrase is the same observation. It is not independent evidence.', 'seo-studio' ),
				__( 'A matching intent supports a closer look. It is not proof of competition, even when an editor confirmed it.', 'seo-studio' ),
				__( 'Zoop does not change the pages.', 'seo-studio' ),
			),
		);
	}

	/**
	 * Normalise one page and record where its topic and intent came from.
	 *
	 * @param array<string, mixed> $page Raw page.
	 * @return array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}
	 */
	private function prepare( array $page ): array {
		$keyphrase = $this->fold( (string) ( $page['keyphrase'] ?? '' ) );
		$topic     = $this->fold( (string) ( $page['topic'] ?? '' ) );
		$title     = $this->fold( (string) ( $page['title'] ?? '' ) );
		$heading   = $this->fold( (string) ( $page['heading'] ?? $page['h1'] ?? '' ) );
		$intent    = $this->fold( (string) ( $page['intent'] ?? '' ) );
		if ( '' === $intent ) {
			$intent = 'unassigned';
		}

		$topic_source = (string) ( $page['topic_source'] ?? '' );
		if ( '' === $topic_source ) {
			$topic_source = $this->inferredTopicSource( $topic, $keyphrase, $heading, $title );
		}
		$intent_source = (string) ( $page['intent_source'] ?? '' );
		if ( '' === $intent_source || 'unassigned' === $intent ) {
			$intent_source = 'unassigned' === $intent ? 'unassigned' : ( '' !== $intent_source ? $intent_source : 'suggested' );
		}

		return array(
			'post_id'       => (int) ( $page['post_id'] ?? 0 ),
			'title'         => trim( (string) ( $page['title'] ?? '' ) ),
			'keyphrase'     => $keyphrase,
			'topic'         => $topic,
			'intent'        => $intent,
			'topic_source'  => $topic_source,
			'intent_source' => $intent_source,
		);
	}

	/**
	 * Guess a topic origin when the caller did not record one.
	 */
	private function inferredTopicSource( string $topic, string $keyphrase, string $heading, string $title ): string {
		if ( '' === $topic ) {
			return 'none';
		}
		if ( '' !== $keyphrase && $topic === $keyphrase ) {
			return 'keyphrase';
		}
		if ( '' !== $heading && $topic === $heading ) {
			return 'heading';
		}
		if ( '' !== $title && $topic === $title ) {
			return 'title';
		}
		return 'saved';
	}

	/**
	 * True when every topic in the bucket is the page's own target phrase.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}> $bucket
	 */
	private function topicRepeatsPhrase( array $bucket ): bool {
		foreach ( $bucket as $page ) {
			if ( '' === $page['topic'] || $page['topic'] !== $page['keyphrase'] ) {
				return false;
			}
			if ( 'keyphrase' !== $page['topic_source'] && 'saved' !== $page['topic_source'] ) {
				return false;
			}
		}
		return array() !== $bucket;
	}

	/**
	 * One confirmed phrase match, with the conclusion left open.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}> $bucket
	 * @return array<string, mixed>
	 */
	private function phraseGroup( string $phrase, array $bucket ): array {
		$evidence = array(
			$this->evidence(
				'target_phrase',
				'saved',
				'high',
				'confirmed_overlap',
				true,
				__( 'Both pages use the target phrase saved by the editor. This confirms that the phrases match.', 'seo-studio' )
			),
		);
		$topic    = $this->repeatedTopicEvidence( $bucket );
		if ( array() !== $topic ) {
			$evidence[] = $topic;
		}
		$evidence[] = $this->intentEvidence( $bucket );
		$evidence[] = $this->competitionEvidence();

		return $this->finding(
			'phrase',
			'high',
			$phrase,
			$bucket,
			$evidence,
			__( 'These pages use the same target phrase. That confirms the phrases match. It does not prove the pages compete. Zoop does not edit the pages.', 'seo-studio' )
		);
	}

	/**
	 * A shared topic that is not just the target phrase repeated.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}> $bucket
	 * @return array<string, mixed>
	 */
	private function topicGroup( string $phrase, array $bucket ): array {
		$independent = $this->topicIsIndependent( $bucket );
		$evidence    = array(
			$this->evidence(
				'primary_topic',
				$independent ? 'saved' : $this->dominantTopicSource( $bucket ),
				$independent ? 'high' : 'medium',
				$independent ? 'confirmed_overlap' : 'same_observation',
				$independent,
				$independent
					? __( 'The editors saved the same primary topic, separately from the target phrases. This confirms a shared topic. It does not prove the pages compete.', 'seo-studio' )
					: __( 'The shared topic was taken from a heading, title, or target phrase. It is not an independent observation, and it does not prove the pages compete.', 'seo-studio' )
			),
			$this->intentEvidence( $bucket ),
			$this->competitionEvidence(),
		);

		return $this->finding(
			'topic',
			$independent ? 'high' : 'medium',
			$phrase,
			$bucket,
			$evidence,
			$independent
				? __( 'These pages share a saved primary topic. That is a separate observation from their target phrases. It does not prove the pages compete. Zoop does not edit the pages.', 'seo-studio' )
				: __( 'These pages share a primary topic taken from another field. That is not independent evidence, and it does not prove the pages compete. Zoop does not edit the pages.', 'seo-studio' )
		);
	}

	/**
	 * Topic evidence attached to a phrase match when the topic only repeats that phrase.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}> $bucket
	 * @return array<string, mixed>
	 */
	private function repeatedTopicEvidence( array $bucket ): array {
		if ( ! $this->topicRepeatsPhrase( $bucket ) ) {
			return array();
		}
		$saved = true;
		foreach ( $bucket as $page ) {
			if ( 'saved' !== $page['topic_source'] ) {
				$saved = false;
				break;
			}
		}

		return $this->evidence(
			'primary_topic',
			$saved ? 'saved' : 'keyphrase',
			'high',
			'same_observation',
			false,
			$saved
				? __( 'A saved topic uses the same words as the target phrase. Confirming those words does not create a second observation.', 'seo-studio' )
				: __( 'The primary topic was suggested from that target phrase. It is the same observation, not a second one.', 'seo-studio' )
		);
	}

	/**
	 * Intent can support a review. It cannot prove a conflict, and unknown intent is not agreement.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}> $bucket
	 * @return array<string, mixed>
	 */
	private function intentEvidence( array $bucket ): array {
		$explicit = array();
		$unknown  = false;
		$saved    = true;
		foreach ( $bucket as $page ) {
			if ( 'unassigned' === $page['intent'] || 'unassigned' === $page['intent_source'] ) {
				$unknown = true;
				continue;
			}
			$explicit[ $page['intent'] ] = true;
			if ( 'saved' !== $page['intent_source'] ) {
				$saved = false;
			}
		}
		if ( $unknown || 1 !== count( $explicit ) ) {
			return $this->evidence(
				'search_intent',
				'unassigned',
				'insufficient',
				'not_established',
				false,
				$unknown
					? __( 'At least one page has no assigned intent. Unknown intent is not agreement.', 'seo-studio' )
					: __( 'The intent labels differ or are missing. That does not establish a conflict.', 'seo-studio' )
			);
		}

		return $this->evidence(
			'search_intent',
			$saved ? 'saved' : 'suggested',
			$saved ? 'high' : 'medium',
			'supports_investigation',
			$saved,
			$saved
				? __( 'Both pages have the same confirmed intent. That supports a closer look. It is not proof that the pages compete.', 'seo-studio' )
				: __( 'Both pages have the same suggested intent. The suggestion comes from wording on the page. It is not proof that the pages compete.', 'seo-studio' )
		);
	}

	/**
	 * The competition claim stays unestablished until stronger evidence exists.
	 *
	 * @return array<string, mixed>
	 */
	private function competitionEvidence(): array {
		return $this->evidence(
			'cannibalisation',
			'none',
			'insufficient',
			'not_established',
			false,
			__( 'Zoop has not established that these pages compete. Pages can share a phrase and still serve different purposes.', 'seo-studio' )
		);
	}

	/**
	 * True when every page has a saved topic that is not its target phrase.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}> $bucket
	 */
	private function topicIsIndependent( array $bucket ): bool {
		foreach ( $bucket as $page ) {
			if ( 'saved' !== $page['topic_source'] || '' === $page['topic'] || $page['topic'] === $page['keyphrase'] ) {
				return false;
			}
		}
		return array() !== $bucket;
	}

	/**
	 * Most common recorded topic origin in a bucket.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}> $bucket
	 */
	private function dominantTopicSource( array $bucket ): string {
		$counts = array();
		foreach ( $bucket as $page ) {
			$source            = '' !== $page['topic_source'] ? $page['topic_source'] : 'none';
			$counts[ $source ] = ( $counts[ $source ] ?? 0 ) + 1;
		}
		arsort( $counts );
		$first = array_key_first( $counts );
		return is_string( $first ) ? $first : 'none';
	}

	/**
	 * One evidence row.
	 *
	 * @return array{signal: string, source: string, observation_confidence: string, establishes: string, independent: bool, detail: string}
	 */
	private function evidence( string $signal, string $source, string $confidence, string $establishes, bool $independent, string $detail ): array {
		return array(
			'signal'                 => $signal,
			'source'                 => $source,
			'observation_confidence' => $confidence,
			'establishes'            => $establishes,
			'independent'            => $independent,
			'detail'                 => $detail,
		);
	}

	/**
	 * A reviewable finding. The level is an investigation, not a harm score.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}> $bucket
	 * @param list<array<string, mixed>>                                                                                                            $evidence
	 * @return array<string, mixed>
	 */
	private function finding( string $observation, string $observation_confidence, string $phrase, array $bucket, array $evidence, string $reason ): array {
		return array(
			'level'                  => 'investigation',
			'confidence'             => 'insufficient',
			'observation'            => $observation,
			'observation_confidence' => $observation_confidence,
			'conclusion'             => 'investigation',
			'conclusion_confidence'  => 'insufficient',
			'phrase'                 => $phrase,
			'reason'                 => $reason,
			'evidence'               => $evidence,
			'pages'                  => $this->pageList( $bucket ),
		);
	}

	/**
	 * No shared phrase or topic was available.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}> $prepared
	 * @return array<string, mixed>
	 */
	private function insufficient( array $prepared ): array {
		$with_phrase = 0;
		foreach ( $prepared as $page ) {
			if ( '' !== $page['keyphrase'] || '' !== $page['topic'] ) {
				++$with_phrase;
			}
		}

		return array(
			'level'                  => 'insufficient',
			'confidence'             => 'insufficient',
			'observation'            => 'none',
			'observation_confidence' => 'insufficient',
			'conclusion'             => 'insufficient',
			'conclusion_confidence'  => 'insufficient',
			'phrase'                 => '',
			'reason'                 => $with_phrase < 2
				? __( 'Fewer than two pages have a target phrase or primary topic.', 'seo-studio' )
				: __( 'No shared target phrase or primary topic was found. A shared phrase is not proof of harm.', 'seo-studio' ),
			'evidence'               => array( $this->competitionEvidence() ),
			'pages'                  => array(),
		);
	}

	/**
	 * Group pages that share a folded field.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}> $pages
	 * @return array<string, list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}>>
	 */
	private function buckets( array $pages, string $field ): array {
		$buckets = array();
		foreach ( $pages as $page ) {
			$phrase = $page[ $field ];
			if ( '' === $phrase ) {
				continue;
			}
			$buckets[ $phrase ][] = $page;
		}
		return array_filter( $buckets, static fn( array $bucket ): bool => count( $bucket ) >= 2 );
	}

	/**
	 * Pages shown for one finding.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}> $bucket
	 * @return list<array{post_id: int, title: string}>
	 */
	private function pageList( array $bucket ): array {
		$pages = array();
		foreach ( $bucket as $page ) {
			$pages[] = array(
				'post_id' => $page['post_id'],
				'title'   => $page['title'],
			);
		}
		usort(
			$pages,
			static fn( array $left, array $right ): int => $left['post_id'] <=> $right['post_id']
		);
		return $pages;
	}

	/**
	 * Remember pages that already have a stronger finding.
	 *
	 * @param array<string, true>                                                                                                                   $covered
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}> $bucket
	 */
	private function cover( array &$covered, array $bucket ): void {
		$ids                             = $this->ids( $bucket );
		$covered[ implode( ',', $ids ) ] = true;
		foreach ( $ids as $left ) {
			foreach ( $ids as $right ) {
				if ( $left < $right ) {
					$covered[ $left . ':' . $right ] = true;
				}
			}
		}
	}

	/**
	 * Sorted post ids for a group.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}> $bucket
	 * @return int[]
	 */
	private function ids( array $bucket ): array {
		$ids = array();
		foreach ( $bucket as $page ) {
			$ids[] = $page['post_id'];
		}
		sort( $ids );
		return $ids;
	}

	/**
	 * Shared title words are a weak observation, not a risk score.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}> $pages
	 * @param array<string, true>                                                                                                                   $covered
	 * @return list<array<string, mixed>>
	 */
	private function titleGroups( array $pages, array $covered ): array {
		$stop   = array_fill_keys( $this->functionWords(), true );
		$tokens = array();
		foreach ( $pages as $page ) {
			$words = array();
			foreach ( $this->words( $page['title'] ) as $word ) {
				if ( strlen( $word ) < 3 || isset( $stop[ $word ] ) ) {
					continue;
				}
				$words[ $word ] = true;
			}
			$tokens[ $page['post_id'] ] = $words;
		}
		$groups = array();
		$count  = count( $pages );
		for ( $left = 0; $left < $count; $left++ ) {
			for ( $right = $left + 1; $right < $count; $right++ ) {
				$a   = $pages[ $left ];
				$b   = $pages[ $right ];
				$key = min( $a['post_id'], $b['post_id'] ) . ':' . max( $a['post_id'], $b['post_id'] );
				if ( isset( $covered[ $key ] ) ) {
					continue;
				}
				$shared = array_keys( array_intersect_key( $tokens[ $a['post_id'] ], $tokens[ $b['post_id'] ] ) );
				if ( count( $shared ) < 3 ) {
					continue;
				}
				sort( $shared );
				$groups[] = $this->finding(
					'title',
					'low',
					implode( ' ', $shared ),
					array( $a, $b ),
					array(
						$this->evidence(
							'title_words',
							'title',
							'low',
							'confirmed_overlap',
							true,
							__( 'The titles share words. This is a separate observation from a saved target phrase.', 'seo-studio' )
						),
						$this->competitionEvidence(),
					),
					__( 'The titles share words. A shared phrase is not proof of harm. Zoop does not edit the pages.', 'seo-studio' )
				);
				if ( count( $groups ) >= 20 ) {
					return $groups;
				}
			}
		}
		return $groups;
	}

	/**
	 * Split a title into words.
	 *
	 * @return string[]
	 */
	private function words( string $text ): array {
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', $this->fold( $text ) );
		return is_array( $parts ) ? array_values( array_filter( $parts, static fn( string $word ): bool => '' !== $word ) ) : array();
	}

	private function fold( string $value ): string {
		$folded = preg_replace( '/\s+/u', ' ', trim( mb_strtolower( $value, 'UTF-8' ) ) );
		return is_string( $folded ) ? $folded : '';
	}

	/**
	 * English function words that do not count as shared title evidence.
	 *
	 * @return string[]
	 */
	private function functionWords(): array {
		$words = require dirname( __DIR__ ) . '/Content/FunctionWords/en.php';
		return is_array( $words ) ? array_map( 'strval', $words ) : array();
	}
}
