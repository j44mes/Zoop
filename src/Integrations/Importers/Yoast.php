<?php
/**
 * One-way copy from Yoast SEO post meta.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Integrations\Importers;

/**
 * Reads Yoast keys verified against the public plugin. Never writes them back.
 */
final class Yoast {
	use MetaQuery;

	public function source(): string {
		return 'yoast';
	}

	/**
	 * Count fields that can be copied.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		return array(
			'posts'        => $this->countAny( array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw' ) ),
			'titles'       => $this->countKey( '_yoast_wpseo_title' ),
			'descriptions' => $this->countKey( '_yoast_wpseo_metadesc' ),
			'keyphrases'   => $this->countKey( '_yoast_wpseo_focuskw' ),
			'canonicals'   => $this->countKey( '_yoast_wpseo_canonical' ),
			'robots'       => $this->countKey( '_yoast_wpseo_meta-robots-noindex' ),
		);
	}

	/**
	 * Copy one batch and return the next cursor.
	 *
	 * @return array{processed: int, cursor: int, done: bool, needs_review: int}
	 */
	public function import( int $cursor, int $limit ): array {
		$ids    = $this->idsWithKeys(
			array(
				'_yoast_wpseo_title',
				'_yoast_wpseo_metadesc',
				'_yoast_wpseo_focuskw',
				'_yoast_wpseo_canonical',
				'_yoast_wpseo_meta-robots-noindex',
				'_yoast_wpseo_meta-robots-nofollow',
			),
			$cursor,
			$limit
		);
		$expand = new Expander();
		$review = 0;
		$last   = $cursor;
		foreach ( $ids as $post_id ) {
			$last  = $post_id;
			$title = $this->text( $post_id, '_yoast_wpseo_title' );
			if ( '' !== $title ) {
				$expanded = $expand->expand( $post_id, $title );
				$this->write( $post_id, '_seostudio_title', $expanded );
				if ( $expand->needsReview( $expanded ) ) {
					++$review;
				}
			}
			$this->write( $post_id, '_seostudio_description', $expand->expand( $post_id, $this->text( $post_id, '_yoast_wpseo_metadesc' ) ) );
			$this->write( $post_id, '_seostudio_focus_keyphrase', $this->text( $post_id, '_yoast_wpseo_focuskw' ) );
			$this->write( $post_id, '_seostudio_canonical', $this->text( $post_id, '_yoast_wpseo_canonical' ) );
			$noindex  = '1' === $this->text( $post_id, '_yoast_wpseo_meta-robots-noindex' );
			$nofollow = '1' === $this->text( $post_id, '_yoast_wpseo_meta-robots-nofollow' );
			if ( $noindex || $nofollow ) {
				update_post_meta(
					$post_id,
					'_seostudio_robots',
					array(
						'index'             => ! $noindex,
						'follow'            => ! $nofollow,
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

	private function write( int $postId, string $key, string $value ): void {
		if ( '' === $value ) {
			return;
		}
		update_post_meta( $postId, $key, $value );
	}
}
