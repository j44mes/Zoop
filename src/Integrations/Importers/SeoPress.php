<?php
/**
 * One-way copy from SEOPress post meta.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Integrations\Importers;

/**
 * Reads SEOPress keys. A robots index value of yes means noindex.
 */
final class SeoPress {
	use MetaQuery;

	/**
	 * Source meta keys this importer reads.
	 */
	private const KEYS = array(
		'_seopress_titles_title',
		'_seopress_titles_desc',
		'_seopress_analysis_target_kw',
		'_seopress_robots_canonical',
		'_seopress_robots_index',
	);

	public function source(): string {
		return 'seopress';
	}

	/**
	 * Count fields that can be copied.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		return array(
			'posts'        => $this->countAny( self::KEYS ),
			'titles'       => $this->countKey( '_seopress_titles_title' ),
			'descriptions' => $this->countKey( '_seopress_titles_desc' ),
			'keyphrases'   => $this->countKey( '_seopress_analysis_target_kw' ),
			'canonicals'   => $this->countKey( '_seopress_robots_canonical' ),
			'robots'       => $this->countKey( '_seopress_robots_index' ),
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
			$title = $this->text( $post_id, '_seopress_titles_title' );
			if ( '' !== $title ) {
				$converted = $expand->convert( $title );
				update_post_meta( $post_id, '_seostudio_title', $converted );
				if ( $expand->needsReview( $converted ) ) {
					++$review;
				}
			}
			$description = $expand->convert( $this->text( $post_id, '_seopress_titles_desc' ) );
			if ( '' !== $description ) {
				update_post_meta( $post_id, '_seostudio_description', $description );
			}
			$phrase = $this->text( $post_id, '_seopress_analysis_target_kw' );
			if ( '' !== $phrase ) {
				update_post_meta( $post_id, '_seostudio_focus_keyphrase', $phrase );
			}
			$canonical = $this->text( $post_id, '_seopress_robots_canonical' );
			if ( '' !== $canonical ) {
				update_post_meta( $post_id, '_seostudio_canonical', $canonical );
			}
			if ( 'yes' === $this->text( $post_id, '_seopress_robots_index' ) ) {
				update_post_meta(
					$post_id,
					'_seostudio_robots',
					array(
						'index'             => false,
						'follow'            => true,
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
