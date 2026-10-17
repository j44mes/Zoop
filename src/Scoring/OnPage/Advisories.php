<?php
/**
 * Non-scoring warnings that sit beside the on-page score.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Scoring\OnPage;

use SEOStudio\Content\PageModel;

/**
 * Advisories never change the numeric score.
 */
final class Advisories {
	/**
	 * For page.
	 *
	 * @return array<int, array{id: string, message: string}>
	 */
	public function forPage( PageModel $page, string $keyphrase, Normaliser $normaliser ): array {
		$items = array();
		$words = $normaliser->wordCount( $page->bodyText );
		$match = $normaliser->matchField( $page->bodyText, $keyphrase );
		$width = count( $match->contentWords );

		if ( $words > 0 && $width > 0 ) {
			$density = ( $match->entireCount * $width ) / $words;
			if ( $density > 0.03 ) {
				$items[] = array(
					'id'      => 'advisory.density',
					'message' => __( 'The keyphrase is repeated in more than 3% of body words. That reads as stuffing, even when the score rewards mentions.', 'seo-studio' ),
				);
			}
		}

		if ( $this->titlePixels( $page->titleTag ) > 600 ) {
			$items[] = array(
				'id'      => 'advisory.title_width',
				'message' => __( 'The title is wider than 600 pixels and may be truncated in search results.', 'seo-studio' ),
			);
		}

		$description_length = mb_strlen( trim( $page->metaDescription ) );
		if ( $description_length > 0 && ( $description_length < 120 || $description_length > 157 ) ) {
			$items[] = array(
				'id'      => 'advisory.meta_length',
				'message' => __( 'The meta description is outside 120 to 157 characters.', 'seo-studio' ),
			);
		}

		$subheadings = $page->subheadings();
		if ( array() !== $subheadings ) {
			$hits = 0;
			foreach ( $subheadings as $heading ) {
				if ( $normaliser->matchField( $heading['text'], $keyphrase )->partial ) {
					++$hits;
				}
			}
			if ( ( $hits / count( $subheadings ) ) > 0.75 ) {
				$items[] = array(
					'id'      => 'advisory.subheadings',
					'message' => __( 'More than 75% of subheadings contain the keyphrase. Vary them so the outline stays readable.', 'seo-studio' ),
				);
			}
		}

		if ( 'article' === $page->pageType && $words < 300 ) {
			$items[] = array(
				'id'      => 'advisory.article_length',
				'message' => __( 'This article body is under 300 words. Develop the topic rather than adding filler.', 'seo-studio' ),
			);
		}

		return $items;
	}

	/**
	 * Approximate Arial-like title width. This is an advisory only.
	 */
	public function titlePixels( string $title ): int {
		$width = 0;
		$chars = preg_split( '//u', $title, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $chars ) ) {
			return 0;
		}

		foreach ( $chars as $char ) {
			if ( in_array( $char, array( ' ', 'i', 'l', 'I', '|', '.', ',', ':', ';', '!' ), true ) ) {
				$width += 4;
			} elseif ( in_array( $char, array( 'm', 'w', 'M', 'W' ), true ) ) {
				$width += 12;
			} else {
				$width += 8;
			}
		}

		return $width;
	}
}
