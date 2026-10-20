<?php
/**
 * Applies the on-page ruleset marker.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Core;

use SEOStudio\Scoring\OnPage\Ruleset;
use SEOStudio\Storage\PagesRepository;

/**
 * Retires incompatible current scores, then records the ruleset that was applied.
 *
 * Schema version only introduces the mechanism. Later ruleset changes run from
 * this comparison even when the schema is already current.
 */
final class RulesetApplier {
	/**
	 * Retire current scores that do not carry the target ruleset.
	 *
	 * The marker is updated only after the retirement query succeeds. A failed
	 * query leaves the previous marker so the next request can retry.
	 *
	 * @param int|null $ruleset Null uses the ruleset compiled into this build. Tests pass another integer to simulate a later ruleset without a schema change.
	 */
	public static function synchronise( ?int $ruleset = null ): bool {
		$target  = null === $ruleset ? Ruleset::CURRENT : $ruleset;
		$applied = (int) get_option( Ruleset::OPTION, 0 );
		if ( $applied === $target ) {
			return true;
		}

		$repository = new PagesRepository();
		UpgradeNotice::rememberDefinitive( $repository->currentDefinitiveIds() );
		$retired = $repository->retireIncompatibleRuleset( $target );
		if ( ! $retired ) {
			return false;
		}

		UpgradeNotice::recordRetired( $target );
		update_option( Ruleset::OPTION, $target, true );

		return (int) get_option( Ruleset::OPTION, 0 ) === $target;
	}
}
