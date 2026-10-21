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
	public const CURRENT = 2;

	public const OPTION = 'seostudio_applied_onpage_ruleset';

	/**
	 * True only when the stored JSON value is the integer itself.
	 *
	 * A string or a longer integer such as 10 is not the current ruleset.
	 */
	public static function matches( mixed $stored ): bool {
		return is_int( $stored ) && self::CURRENT === $stored;
	}
}
