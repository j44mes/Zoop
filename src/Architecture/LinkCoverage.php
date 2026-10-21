<?php
/**
 * Published-link coverage for one stored page.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Architecture;

/**
 * The same classification the link graph uses, without rebuilding the graph.
 */
final class LinkCoverage {
	public const FRESH        = 'fresh';
	public const UNANALYSED   = 'unanalysed';
	public const CONTENT_ONLY = 'content_only';
	public const STALE        = 'stale_links';

	/**
	 * Why a published post does, or does not, have current published-link evidence.
	 *
	 * @param array{render_mode?: string, analysed?: bool, model?: array<string, mixed>}|null $stored Stored analysis, when one exists.
	 */
	public static function reason( ?array $stored, int $postId, string $navigation ): string {
		if ( ! is_array( $stored ) || empty( $stored['analysed'] ) ) {
			return self::UNANALYSED;
		}
		if ( 'live' !== (string) ( $stored['render_mode'] ?? '' ) ) {
			return self::CONTENT_ONLY;
		}
		$model = isset( $stored['model'] ) && is_array( $stored['model'] ) ? $stored['model'] : array();
		if ( ! LinkSource::isFresh( $model, $postId, $navigation ) ) {
			return self::STALE;
		}

		return self::FRESH;
	}

	public static function needsPublishedCheck( string $reason ): bool {
		return self::FRESH !== $reason;
	}
}
