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

	/**
	 * Stable phrase identity for overlap grouping.
	 *
	 * Case, hyphens, and regular English plurals share one key. Function words stay exact.
	 */
	public function phraseKey( string $text ): string {
		$tokens = array();
		foreach ( $this->tokens( $text ) as $token ) {
			$tokens[] = $this->canonicalToken( $token );
		}

		return implode( ' ', $tokens );
	}

	/**
	 * True when two tokens are the same word or a regular singular/plural pair.
	 */
	public function equivalent( string $left, string $right ): bool {
		return $this->canonicalToken( $left ) === $this->canonicalToken( $right );
	}

	/**
	 * Singular form for a regular plural, otherwise the token itself.
	 */
	public function canonicalToken( string $token ): string {
		if ( $this->isFunctionWord( $token ) || $this->isInvariant( $token ) ) {
			return $token;
		}

		$singular = $this->regularSingular( $token );
		return null === $singular ? $token : $singular;
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
			if ( $this->containsToken( $tokens, $word ) ) {
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
			if ( $this->containsToken( $tokens, $word ) ) {
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
			if ( ! $this->equivalent( (string) ( $tokens[ $index + $offset ] ?? '' ), $word ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the field contains this content word, including a regular plural.
	 *
	 * @param string[] $tokens Field tokens.
	 */
	private function containsToken( array $tokens, string $word ): bool {
		foreach ( $tokens as $token ) {
			if ( $this->equivalent( $word, $token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Regular English plural, or null when the word is too short or not safe to change.
	 */
	private function regularPlural( string $token ): ?string {
		if ( mb_strlen( $token ) < 4 || $this->isFunctionWord( $token ) || $this->isInvariant( $token ) ) {
			return null;
		}

		if ( 1 === preg_match( '/(?:s|x|z|ch|sh)\z/u', $token ) ) {
			$plural = $token . 'es';
		} elseif ( 1 === preg_match( '/[^aeiou]y\z/u', $token ) ) {
			$plural = mb_substr( $token, 0, -1 ) . 'ies';
		} else {
			$plural = $token . 's';
		}

		return $this->isInvariant( $plural ) ? null : $plural;
	}

	/**
	 * Stem of a regular -s plural when that stem pluralises back to the token.
	 *
	 * -ies and -es forms are left unchanged. Guessing them also fits unrelated words.
	 */
	private function regularSingular( string $token ): ?string {
		if ( $this->isInvariant( $token ) || mb_strlen( $token ) < 5 || ! $this->endsWith( $token, 's' ) || $this->endsWith( $token, 'ss' ) ) {
			return null;
		}

		$stem = mb_substr( $token, 0, -1 );
		if ( $this->regularPlural( $stem ) !== $token ) {
			return null;
		}

		return $stem;
	}

	private function endsWith( string $token, string $suffix ): bool {
		$length = mb_strlen( $suffix );

		return $length <= mb_strlen( $token ) && mb_substr( $token, -$length ) === $suffix;
	}

	/**
	 * Forms that must not be split into a guessed singular.
	 */
	private function isInvariant( string $token ): bool {
		return in_array( $token, array( 'news', 'series', 'species' ), true );
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
