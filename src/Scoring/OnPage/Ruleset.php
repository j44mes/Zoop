<?php
/**
 * On-page scoring ruleset.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Scoring\OnPage;

/**
 * The ruleset is independent of the plugin version and the evidence version.
 */
final class Ruleset {
	public const CURRENT = 1;

	public const OPTION = 'seostudio_applied_onpage_ruleset';

	public static function matches( int $stored ): bool {
		return self::CURRENT === $stored;
	}
}
