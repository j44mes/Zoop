<?php
/**
 * Keyphrase and text normalisation.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Scoring\OnPage;

use Normalizer;

/**
 * Lowercases, applies NFKC, and matches a keyphrase literally or by its content words.
 */
final class Normaliser {
	/**
	 * Cached function-word list.
	 *
	 * @var string[]|null
	 */
	private ?array $functionWords = null;

	public function normalise( string $text ): string {
		if ( class_exists( Normalizer::class ) ) {
			$normalised = Normalizer::normalize( $text, Normalizer::FORM_KC );
			$text       = is_string( $normalised ) ? $normalised : $text;
		}

		$text = mb_strtolower( $text, 'UTF-8' );
		$text = preg_replace( '/\p{P}+/u', ' ', $text ) ?? $text;
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;

		return trim( $text );
	}

	/**
	 * Return the normalised tokens of a field.
	 *
	 * @return string[]
	 */
	public function tokens( string $text ): array {
		$normalised = $this->normalise( $text );
		if ( '' === $normalised ) {
			return array();
		}

		return explode( ' ', $normalised );
	}

	/**
	 * Content words.
	 *
	 * @return string[]
	 */
	public function contentWords( string $keyphrase ): array {
		$words = array();
		foreach ( $this->tokens( $keyphrase ) as $token ) {
			if ( ! $this->isFunctionWord( $token ) ) {
				$words[] = $token;
			}
		}

		return $words;
	}

	public function isFunctionWord( string $token ): bool {
		return in_array( $token, $this->functionWords(), true );
	}

	public function matchField( string $field, string $keyphrase ): FieldMatch {
		$content_words = $this->contentWords( $keyphrase );
		$phrase        = $this->tokens( $keyphrase );
		$tokens        = $this->tokens( $field );
		$index         = $this->firstEntireIndex( $tokens, $phrase );

		return new FieldMatch(
			$content_words,
			null !== $index,
			$this->hasPartial( $tokens, $content_words ),
			$this->coverage( $tokens, $content_words ),
			null === $index ? null : $index + 1,
			count( $tokens ),
			$this->countEntire( $tokens, $phrase )
		);
	}

	public function wordCount( string $text ): int {
		return count( $this->tokens( $text ) );
	}

	/**
	 * Share of keyphrase content words present in the field.
	 *
	 * @param string[] $tokens
	 * @param string[] $contentWords
	 */
	private function coverage( array $tokens, array $contentWords ): float {
		$unique = array_values( array_unique( $contentWords ) );
		if ( array() === $unique ) {
			return 0.0;
		}

		$found = 0;
		foreach ( $unique as $word ) {
			if ( in_array( $word, $tokens, true ) ) {
				++$found;
			}
		}

		return $found / count( $unique );
	}

	/**
	 * Has partial.
	 *
	 * @param string[] $tokens
	 * @param string[] $contentWords
	 */
	private function hasPartial( array $tokens, array $contentWords ): bool {
		foreach ( $contentWords as $word ) {
			if ( in_array( $word, $tokens, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Count entire.
	 *
	 * @param string[] $tokens
	 * @param string[] $contentWords
	 */
	private function countEntire( array $tokens, array $contentWords ): int {
		if ( array() === $contentWords ) {
			return 0;
		}

		$count = 0;
		$index = 0;
		$width = count( $contentWords );
		$limit = count( $tokens );

		while ( $index <= $limit - $width ) {
			if ( $this->sliceMatches( $tokens, $index, $contentWords ) ) {
				++$count;
				$index += $width;
				continue;
			}
			++$index;
		}

		return $count;
	}

	/**
	 * First entire index.
	 *
	 * @param string[] $tokens
	 * @param string[] $contentWords
	 */
	private function firstEntireIndex( array $tokens, array $contentWords ): ?int {
		if ( array() === $contentWords ) {
			return null;
		}

		$width = count( $contentWords );
		$limit = count( $tokens ) - $width;
		for ( $index = 0; $index <= $limit; $index++ ) {
			if ( $this->sliceMatches( $tokens, $index, $contentWords ) ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Slice matches.
	 *
	 * @param string[] $tokens
	 * @param string[] $contentWords
	 */
	private function sliceMatches( array $tokens, int $index, array $contentWords ): bool {
		foreach ( $contentWords as $offset => $word ) {
			if ( ( $tokens[ $index + $offset ] ?? '' ) !== $word ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Function words.
	 *
	 * @return string[]
	 */
	private function functionWords(): array {
		if ( null !== $this->functionWords ) {
			return $this->functionWords;
		}

		$path  = dirname( __DIR__, 2 ) . '/Content/FunctionWords/en.php';
		$words = is_readable( $path ) ? require $path : array();
		if ( ! is_array( $words ) ) {
			$words = array();
		}

		$words = apply_filters( 'seostudio/function_words', $words ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Published filter name.
		if ( ! is_array( $words ) ) {
			$words = array();
		}

		$this->functionWords = array_values( array_map( 'strval', $words ) );
		return $this->functionWords;
	}
}
