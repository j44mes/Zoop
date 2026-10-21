<?php
/**
 * Cannibalisation evidence.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Topics;

/**
 * Rates shared phrases without treating them as proof that pages compete.
 */
final class Overlap {
	/**
	 * Compare topics, target phrases, and title words.
	 *
	 * @param list<array<string, mixed>> $pages
	 * @return array{groups: list<array<string, mixed>>, notes: string[]}
	 */
	public function assess( array $pages ): array {
		$prepared = array();
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$post_id = (int) ( $page['post_id'] ?? 0 );
			if ( $post_id <= 0 ) {
				continue;
			}
			$prepared[] = array(
				'post_id'   => $post_id,
				'title'     => trim( (string) ( $page['title'] ?? '' ) ),
				'keyphrase' => $this->fold( (string) ( $page['keyphrase'] ?? '' ) ),
				'topic'     => $this->fold( (string) ( $page['topic'] ?? '' ) ),
				'intent'    => $this->fold( (string) ( $page['intent'] ?? '' ) ),
			);
		}

		$groups  = array();
		$covered = array();
		foreach ( $this->buckets( $prepared, 'keyphrase' ) as $phrase => $bucket ) {
			$groups[] = $this->group( 'high', 'high', $phrase, $bucket, __( 'These pages use the same target phrase. A shared phrase is not proof that the pages compete.', 'seo-studio' ) );
			$this->cover( $covered, $bucket );
		}
		foreach ( $this->buckets( $prepared, 'topic' ) as $phrase => $bucket ) {
			$ids = $this->ids( $bucket );
			if ( isset( $covered[ implode( ',', $ids ) ] ) ) {
				continue;
			}
			$intents = array();
			foreach ( $bucket as $page ) {
				if ( '' !== $page['intent'] && 'unassigned' !== $page['intent'] ) {
					$intents[ $page['intent'] ] = true;
				}
			}
			$same     = 1 === count( $intents );
			$groups[] = $this->group(
				$same ? 'high' : 'medium',
				$same ? 'medium' : 'low',
				$phrase,
				$bucket,
				$same
					? __( 'These pages share a primary topic and the same suggested intent. A shared topic is not proof that the pages compete.', 'seo-studio' )
					: __( 'These pages share a primary topic. Their intent differs or is not suggested. A shared topic is not proof that the pages compete.', 'seo-studio' )
			);
			$this->cover( $covered, $bucket );
		}

		$low    = $this->titleGroups( $prepared, $covered );
		$groups = array_merge( $groups, $low );
		if ( array() === $groups ) {
			$with_phrase = 0;
			foreach ( $prepared as $page ) {
				if ( '' !== $page['keyphrase'] || '' !== $page['topic'] ) {
					++$with_phrase;
				}
			}
			$groups[] = array(
				'level'      => 'insufficient',
				'confidence' => 'low',
				'phrase'     => '',
				'reason'     => $with_phrase < 2
					? __( 'Fewer than two pages have a target phrase or primary topic.', 'seo-studio' )
					: __( 'No shared target phrase or primary topic was found. A shared phrase is not proof of harm.', 'seo-studio' ),
				'pages'      => array(),
			);
		}

		return array(
			'groups' => array_slice( $groups, 0, 50 ),
			'notes'  => array(
				__( 'A shared phrase is not proof of harm.', 'seo-studio' ),
				__( 'The level uses the target phrase, the topic, and title words. Zoop does not change the pages.', 'seo-studio' ),
			),
		);
	}

	/**
	 * Group pages that share a phrase.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string}> $pages
	 * @return array<string, list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string}>>
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
	 * One reviewable overlap.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string}> $bucket
	 * @return array<string, mixed>
	 */
	private function group( string $level, string $confidence, string $phrase, array $bucket, string $reason ): array {
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

		return array(
			'level'      => $level,
			'confidence' => $confidence,
			'phrase'     => $phrase,
			'reason'     => $reason,
			'pages'      => $pages,
		);
	}

	/**
	 * Remember pages that already have a stronger finding.
	 *
	 * @param array<string, true>                                                                       $covered
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string}> $bucket
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
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string}> $bucket
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
	 * Low findings from three or more shared title words.
	 *
	 * @param list<array{post_id: int, title: string, keyphrase: string, topic: string, intent: string}> $pages
	 * @param array<string, true>                                                                       $covered
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
				$groups[] = $this->group(
					'low',
					'low',
					implode( ' ', $shared ),
					array( $a, $b ),
					__( 'The titles share words. A shared phrase is not proof of harm.', 'seo-studio' )
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
