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
	 * @return array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string, topic_confirmed: bool}
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

		$recorded  = (string) ( $page['topic_source'] ?? '' );
		$confirmed = (bool) ( $page['topic_confirmed'] ?? false );
		if ( 'saved' === $recorded || 'editorial' === $recorded ) {
			$confirmed = true;
		}
		$topic_source  = $this->resolveTopicOrigin( $topic, $keyphrase, $heading, $title, $recorded );
		$intent_source = (string) ( $page['intent_source'] ?? '' );
		if ( '' === $intent_source || 'unassigned' === $intent ) {
			$intent_source = 'unassigned' === $intent ? 'unassigned' : ( '' !== $intent_source ? $intent_source : 'suggested' );
		}

		return array(
			'post_id'         => (int) ( $page['post_id'] ?? 0 ),
			'title'           => trim( (string) ( $page['title'] ?? '' ) ),
			'keyphrase'       => $keyphrase,
			'topic'           => $topic,
			'intent'          => $intent,
			'topic_source'    => $topic_source,
			'intent_source'   => $intent_source,
			'topic_confirmed' => $confirmed,
		);
	}

	/**
	 * Content origin of a topic. A match with an existing field wins over a saved label.
	 */
	public static function topicOrigin( string $topic, string $keyphrase, string $heading, string $title ): string {
		$overlap = new self();
		return $overlap->resolveTopicOrigin(
			$overlap->fold( $topic ),
			$overlap->fold( $keyphrase ),
			$overlap->fold( $heading ),
			$overlap->fold( $title ),
			''
		);
	}

	/**
	 * Prefer a matching field. Otherwise keep a recorded origin, or call an unmatched topic editorial.
	 */
	private function resolveTopicOrigin( string $topic, string $keyphrase, string $heading, string $title, string $recorded ): string {
		$matched = $this->matchedField( $topic, $keyphrase, $heading, $title );
		if ( '' !== $matched ) {
			return $matched;
		}
		if ( in_array( $recorded, array( 'keyphrase', 'heading', 'title', 'editorial' ), true ) ) {
			return $recorded;
		}
		if ( '' === $topic ) {
			return 'none';
		}
		return 'editorial';
	}

	/**
	 * Field whose words are the topic, in the suggestion order.
	 */
	private function matchedField( string $topic, string $keyphrase, string $heading, string $title ): string {
		if ( '' === $topic ) {
			return '';
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
		return '';
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
			if ( 'keyphrase' !== $page['topic_source'] && 'editorial' !== $page['topic_source'] && 'saved' !== $page['topic_source'] ) {
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
				__( 'These pages use the target phrase saved by the editor. This confirms that the phrases match.', 'seo-studio' )
			),
		);
		foreach ( $this->repeatedTopicRows( $bucket ) as $topic ) {
			$evidence[] = $topic;
		}
		foreach ( $this->intentEvidenceRows( $bucket ) as $intent ) {
			$evidence[] = $intent;
		}
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
		$sources     = $this->topicSources( $bucket );
		$evidence    = $this->topicSourceRows( $bucket );
		foreach ( $this->intentEvidenceRows( $bucket ) as $intent ) {
			$evidence[] = $intent;
		}
		$evidence[] = $this->competitionEvidence();

		if ( $independent ) {
			$reason = __( 'These pages share a primary topic specified by the editor. The words do not come from their target phrases, headings, or titles. It does not prove the pages compete. Zoop does not edit the pages.', 'seo-studio' );
		} elseif ( count( $sources ) > 1 ) {
			$reason = __( 'These pages share a primary topic, and the sources differ. Each page keeps its own source. A topic the editor specified is a separate observation for that page. A topic taken from the heading, title, or target phrase is not independent evidence, even when the editor accepted it. This does not prove the pages compete. Zoop does not edit the pages.', 'seo-studio' );
		} else {
			$reason = __( 'These pages share a primary topic taken from another field. Accepting that wording does not make it independent evidence, and it does not prove the pages compete. Zoop does not edit the pages.', 'seo-studio' );
		}

		return $this->finding(
			'topic',
			$independent ? 'high' : 'medium',
			$phrase,
			$bucket,
			$evidence,
			$reason
		);
	}

	/**
	 * Topic rows for a phrase match, split by the source on each page.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}> $bucket
	 * @return list<array<string, mixed>>
	 */
	private function repeatedTopicRows( array $bucket ): array {
		if ( ! $this->topicRepeatsPhrase( $bucket ) ) {
			return array();
		}
		$rows = array();
		foreach ( $this->pagesBy( $bucket, 'topic_source' ) as $source => $pages ) {
			$one    = 1 === count( $pages );
			$rows[] = $this->evidence(
				'primary_topic',
				(string) $source,
				'high',
				'same_observation',
				false,
				$one
					? __( 'This page uses a primary topic with the same words as its target phrase. Accepting those words does not create a second observation.', 'seo-studio' )
					: __( 'These pages use a primary topic with the same words as the target phrase. Accepting those words does not create a second observation.', 'seo-studio' ),
				$pages,
				'topic_source'
			);
		}
		return $rows;
	}

	/**
	 * One topic row for each source in the group.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}> $bucket
	 * @return list<array<string, mixed>>
	 */
	private function topicSourceRows( array $bucket ): array {
		$rows = array();
		foreach ( $this->pagesBy( $bucket, 'topic_source' ) as $source => $pages ) {
			$independent = 'editorial' === $source;
			$rows[]      = $this->evidence(
				'primary_topic',
				(string) $source,
				$independent ? 'high' : 'medium',
				$independent ? 'confirmed_overlap' : 'same_observation',
				$independent,
				$this->topicSourceDetail( (string) $source, count( $pages ) ),
				$pages,
				'topic_source'
			);
		}
		return $rows;
	}

	/**
	 * Sentence for one topic source. It describes only the pages that use that source.
	 */
	private function topicSourceDetail( string $source, int $count ): string {
		$one = 1 === $count;
		if ( 'editorial' === $source ) {
			return $one
				? __( 'This page has a primary topic specified by the editor. The words do not come from its target phrase, heading, or title. That is a separate observation for this page. It does not prove the pages compete.', 'seo-studio' )
				: __( 'These pages have a primary topic specified by the editor. The words do not come from their target phrases, headings, or titles. That is a separate observation. It does not prove the pages compete.', 'seo-studio' );
		}
		if ( 'heading' === $source ) {
			return $one
				? __( 'This page has a primary topic taken from its first heading. Accepting that suggestion does not make it an independent observation.', 'seo-studio' )
				: __( 'These pages have a primary topic taken from the first heading. Accepting that suggestion does not make it an independent observation.', 'seo-studio' );
		}
		if ( 'title' === $source ) {
			return $one
				? __( 'This page has a primary topic taken from its title. Accepting that suggestion does not make it an independent observation.', 'seo-studio' )
				: __( 'These pages have a primary topic taken from the page title. Accepting that suggestion does not make it an independent observation.', 'seo-studio' );
		}
		if ( 'keyphrase' === $source ) {
			return $one
				? __( 'This page has a primary topic taken from its target phrase. It is the same observation, not a second one.', 'seo-studio' )
				: __( 'These pages have a primary topic taken from the target phrase. It is the same observation, not a second one.', 'seo-studio' );
		}
		return $one
			? __( 'This page has a primary topic with no recorded source. It is not an independent observation.', 'seo-studio' )
			: __( 'These pages have a primary topic with no recorded source. It is not an independent observation.', 'seo-studio' );
	}

	/**
	 * Intent rows split by source. Unknown intent is not agreement, and a match is not proof.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}> $bucket
	 * @return list<array<string, mixed>>
	 */
	private function intentEvidenceRows( array $bucket ): array {
		$unknown   = array();
		$by_intent = array();
		foreach ( $bucket as $page ) {
			if ( 'unassigned' === $page['intent'] || 'unassigned' === $page['intent_source'] ) {
				$unknown[] = $page;
				continue;
			}
			$by_intent[ $page['intent'] ][ $page['intent_source'] ][] = $page;
		}
		if ( array() !== $unknown || 1 !== count( $by_intent ) ) {
			return array(
				$this->evidence(
					'search_intent',
					'unassigned',
					'insufficient',
					'not_established',
					false,
					array() !== $unknown
						? __( 'At least one page has no assigned intent. Unknown intent is not agreement.', 'seo-studio' )
						: __( 'The intent labels differ or are missing. That does not establish a conflict.', 'seo-studio' ),
					$bucket,
					'intent_source'
				),
			);
		}

		$rows = array();
		foreach ( $by_intent as $sources ) {
			foreach ( $sources as $source => $pages ) {
				$saved = 'saved' === $source;
				$one   = 1 === count( $pages );
				if ( $saved && $one ) {
					$detail = __( 'This page has a confirmed intent. That supports a closer look. It is not proof that the pages compete.', 'seo-studio' );
				} elseif ( $saved ) {
					$detail = __( 'These pages have the same confirmed intent. That supports a closer look. It is not proof that the pages compete.', 'seo-studio' );
				} elseif ( $one ) {
					$detail = __( 'This page has a suggested intent. The suggestion comes from wording on the page. It is not proof that the pages compete.', 'seo-studio' );
				} else {
					$detail = __( 'These pages have the same suggested intent. The suggestion comes from wording on the page. It is not proof that the pages compete.', 'seo-studio' );
				}
				$rows[] = $this->evidence(
					'search_intent',
					(string) $source,
					$saved ? 'high' : 'medium',
					'supports_investigation',
					$saved,
					$detail,
					$pages,
					'intent_source'
				);
			}
		}
		return $rows;
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
			if ( 'editorial' !== $page['topic_source'] || '' === $page['topic'] ) {
				return false;
			}
		}
		return array() !== $bucket;
	}

	/**
	 * Distinct topic sources in a group.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}> $bucket
	 * @return string[]
	 */
	private function topicSources( array $bucket ): array {
		return array_keys( $this->pagesBy( $bucket, 'topic_source' ) );
	}

	/**
	 * Pages grouped by one recorded source field.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}> $bucket
	 * @return array<string, list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}>>
	 */
	private function pagesBy( array $bucket, string $field ): array {
		$grouped = array();
		foreach ( $bucket as $page ) {
			$source               = '' !== $page[ $field ] ? $page[ $field ] : 'none';
			$grouped[ $source ][] = $page;
		}
		ksort( $grouped );
		return $grouped;
	}

	/**
	 * One evidence row, with the pages that supplied it.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string, topic_source: string, intent_source: string}> $pages
	 * @param string                                                                                                                                 $page_field Field that records each page's own source.
	 * @return array{signal: string, source: string, observation_confidence: string, establishes: string, independent: bool, detail: string, pages: list<array{post_id: int, title: string, source: string, confirmed: bool}>}
	 */
	private function evidence( string $signal, string $source, string $confidence, string $establishes, bool $independent, string $detail, array $pages = array(), string $page_field = '' ): array {
		$listed = array();
		foreach ( $pages as $page ) {
			$page_source = $source;
			if ( '' !== $page_field && isset( $page[ $page_field ] ) && is_string( $page[ $page_field ] ) && '' !== $page[ $page_field ] ) {
				$page_source = $page[ $page_field ];
			}
			$listed[] = array(
				'post_id'   => $page['post_id'],
				'title'     => $page['title'],
				'source'    => $page_source,
				'confirmed' => 'topic_source' === $page_field && ! empty( $page['topic_confirmed'] ),
			);
		}

		return array(
			'signal'                 => $signal,
			'source'                 => $source,
			'observation_confidence' => $confidence,
			'establishes'            => $establishes,
			'independent'            => $independent,
			'detail'                 => $detail,
			'pages'                  => $listed,
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
