<?php
/**
 * One-way copy from Rank Math post meta.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Integrations\Importers;

use SEOStudio\Storage\SeoMeta;

/**
 * Reads Rank Math keys. The focus keyword is a comma-separated list.
 */
final class RankMath {
	use MetaQuery;

	/**
	 * Source meta keys this importer reads.
	 */
	private const KEYS = array(
		'rank_math_title',
		'rank_math_description',
		'rank_math_focus_keyword',
		'rank_math_canonical_url',
		'rank_math_robots',
	);

	public function source(): string {
		return 'rankmath';
	}

	/**
	 * Count fields that can be copied.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		return array(
			'posts'        => $this->countAny( self::KEYS ),
			'titles'       => $this->countKey( 'rank_math_title' ),
			'descriptions' => $this->countKey( 'rank_math_description' ),
			'keyphrases'   => $this->countKey( 'rank_math_focus_keyword' ),
			'canonicals'   => $this->countKey( 'rank_math_canonical_url' ),
			'robots'       => $this->countKey( 'rank_math_robots' ),
			'overwrites'   => $this->countOverwrites( self::KEYS ),
		);
	}

	/**
	 * Copy one batch and return the next cursor.
	 *
	 * @return array{processed: int, cursor: int, done: bool, needs_review: int}
	 */
	public function import( int $cursor, int $limit ): array {
		$ids    = $this->idsWithKeys(
			self::KEYS,
			$cursor,
			$limit
		);
		$expand = new Expander();
		$review = 0;
		$last   = $cursor;
		foreach ( $ids as $post_id ) {
			$last  = $post_id;
			$title = $this->text( $post_id, 'rank_math_title' );
			if ( '' !== $title ) {
				$converted = $expand->convert( $title );
				SeoMeta::store( $post_id, '_seostudio_title', $converted );
				if ( $expand->needsReview( $converted ) ) {
					++$review;
				}
			}
			$description = $expand->convert( $this->text( $post_id, 'rank_math_description' ) );
			if ( '' !== $description ) {
				SeoMeta::store( $post_id, '_seostudio_description', $description );
			}
			$keywords = $this->text( $post_id, 'rank_math_focus_keyword' );
			if ( '' !== $keywords ) {
				$parts = array_values( array_filter( array_map( 'trim', explode( ',', $keywords ) ) ) );
				if ( isset( $parts[0] ) ) {
					SeoMeta::store( $post_id, '_seostudio_focus_keyphrase', $parts[0] );
				}
				$related = array();
				foreach ( array_slice( $parts, 1, 4 ) as $part ) {
					$related[] = array(
						'keyphrase' => sanitize_text_field( $part ),
						'synonyms'  => array(),
					);
				}
				if ( array() !== $related ) {
					update_post_meta( $post_id, '_seostudio_related_keyphrases', $related );
				}
			}
			$canonical = $this->text( $post_id, 'rank_math_canonical_url' );
			if ( '' !== $canonical ) {
				SeoMeta::store( $post_id, '_seostudio_canonical', $canonical );
			}
			$robots = get_post_meta( $post_id, 'rank_math_robots', true );
			if ( is_array( $robots ) && array() !== $robots ) {
				$flat = array_map( 'strval', $robots );
				update_post_meta(
					$post_id,
					'_seostudio_robots',
					array(
						'index'             => ! in_array( 'noindex', $flat, true ),
						'follow'            => ! in_array( 'nofollow', $flat, true ),
						'max_snippet'       => null,
						'max_image_preview' => null,
					)
				);
			}
		}

		return array(
			'processed'    => count( $ids ),
			'cursor'       => $last,
			'done'         => count( $ids ) < $limit,
			'needs_review' => $review,
		);
	}

	private function text( int $postId, string $key ): string {
		$value = get_post_meta( $postId, $key, true );
		return is_string( $value ) ? trim( $value ) : '';
	}
}
