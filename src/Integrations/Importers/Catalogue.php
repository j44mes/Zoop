<?php
/**
 * Picks an importer by its source slug.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Integrations\Importers;

use InvalidArgumentException;

/**
 * Yoast, Rank Math, and SEOPress are the sources with a writer.
 */
final class Catalogue {
	public static function normalise( string $source ): string {
		$source = sanitize_key( $source );
		if ( 'rank_math' === $source ) {
			return 'rankmath';
		}

		return $source;
	}

	public static function make( string $source ): Yoast|RankMath|SeoPress {
		return match ( self::normalise( $source ) ) {
			'yoast' => new Yoast(),
			'rankmath' => new RankMath(),
			'seopress' => new SeoPress(),
			default => throw new InvalidArgumentException( 'Unknown import source.' ),
		};
	}
}
