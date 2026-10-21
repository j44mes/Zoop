<?php
/**
 * Evidence states for a title or description.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Content;

/**
 * Names the states a metadata value can be in before it is scored.
 */
final class MetadataEvidence {
	public const OBSERVED        = 'observed';
	public const OBSERVED_ABSENT = 'observed_absent';
	public const CALCULATED      = 'calculated';
	public const NOT_OBSERVED    = 'not_observed';

	/**
	 * Keep a known state, or the fallback when the stored value is missing.
	 */
	public static function known( ?string $value, string $fallback ): string {
		if ( null === $value || '' === $value ) {
			return self::valid( $fallback );
		}

		return self::valid( $value );
	}

	/**
	 * Whether expanded text still contains a template token.
	 */
	public static function unresolved( string $value ): bool {
		return 1 === preg_match( '/%%[^%\s]+%%|%[A-Za-z0-9_]+%|#[A-Za-z0-9_]+/', $value );
	}

	/**
	 * A non-empty expanded value with no leftover token is calculated. Anything else is not observed.
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function calculated( string $expanded ): array {
		$expanded = trim( $expanded );
		if ( '' === $expanded || self::unresolved( $expanded ) ) {
			return array( '', self::NOT_OBSERVED );
		}

		return array( $expanded, self::CALCULATED );
	}

	private static function valid( string $value ): string {
		if ( in_array( $value, array( self::OBSERVED, self::OBSERVED_ABSENT, self::CALCULATED, self::NOT_OBSERVED ), true ) ) {
			return $value;
		}

		return self::NOT_OBSERVED;
	}
}
