<?php
/**
 * One-time explanation for scores saved before published-page evidence existed.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Core;

use SEOStudio\Rest\PageRecord;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Storage\Tables;

/**
 * This is not the ruleset-retirement notice. Old scores were not definitive.
 */
final class LegacyScoreNotice {
	public const OPTION = 'seostudio_legacy_score_notice';
	public const META   = 'seostudio_dismissed_legacy_score_notice';

	/**
	 * Remember pages that already had an on-page score when a pre-evidence install is upgraded.
	 *
	 * A fresh install has no saved settings option, so it is ignored. An empty score
	 * history does not create a notice.
	 *
	 * @param int[] $pageIds Pages with a stored on-page score.
	 */
	public static function remember( array $pageIds ): void {
		$pageIds = self::ints( $pageIds );
		if ( array() === $pageIds ) {
			return;
		}

		update_option(
			self::OPTION,
			array(
				'page_ids' => self::ints( array_merge( self::storedIds(), $pageIds ) ),
			),
			false
		);
	}

	/**
	 * Capture saved scores only for an install that already stored a pre-evidence schema.
	 */
	public static function captureUpgrade(): void {
		$stored = get_option( Settings::OPTION, false );
		if ( ! is_array( $stored ) || (int) ( $stored['schema_version'] ?? 0 ) >= 5 ) {
			return;
		}

		self::remember( ( new PagesRepository() )->pagesWithOnPageScores() );
	}

	/**
	 * Recorded pages that still need a published-page check.
	 *
	 * @return int[]
	 */
	public static function outstandingIds(): array {
		global $wpdb;

		$pageIds = self::storedIds();
		if ( array() === $pageIds ) {
			return array();
		}

		$pages = Tables::pages();
		$found = array();
		foreach ( array_chunk( $pageIds, 500 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Internal table. Placeholders match the ids.
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$pages} WHERE onpage_state = 'needs_reanalysis' AND id IN ({$in})",
					$chunk
				)
			);
			// phpcs:enable
			if ( ! is_array( $rows ) ) {
				continue;
			}
			foreach ( $rows as $id ) {
				$found[] = (int) $id;
			}
		}

		return self::ints( $found );
	}

	/**
	 * Banner payload, or null when this user should not see it.
	 *
	 * @return array{count: int, message: string, review_url: string}|null
	 */
	public static function payload(): ?array {
		if ( array() === self::storedIds() ) {
			return null;
		}
		if ( '1' === (string) get_user_meta( get_current_user_id(), self::META, true ) ) {
			return null;
		}

		$count = ( new PageRecord() )->count( array( 'score_state' => 'legacy_scores' ) );
		if ( $count < 1 ) {
			return null;
		}

		return array(
			'count'      => $count,
			'message'    => self::message( $count ),
			'review_url' => 'admin.php?page=seostudio&section=pages&score_state=legacy_scores',
		);
	}

	/**
	 * Hide the explanation for this user only.
	 */
	public static function dismiss(): void {
		if ( get_current_user_id() < 1 || array() === self::storedIds() ) {
			return;
		}

		update_user_meta( get_current_user_id(), self::META, '1' );
	}

	/**
	 * Sentence for scores that were kept but were never definitive.
	 */
	public static function message( int $count ): string {
		$count = max( 0, $count );

		return sprintf(
			/* translators: %d: number of pages the current user can still check after an upgrade from saved pre-evidence scores. */
			_n(
				"Zoop's page checks have been updated. Your previous scores have been kept but are no longer shown. %d page needs a new published-page check.",
				"Zoop's page checks have been updated. Your previous scores have been kept but are no longer shown. %d pages need a new published-page check.",
				$count,
				'seo-studio'
			),
			$count
		);
	}

	/**
	 * Page ids saved for this upgrade.
	 *
	 * @return int[]
	 */
	private static function storedIds(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || ! isset( $stored['page_ids'] ) || ! is_array( $stored['page_ids'] ) ) {
			return array();
		}

		return self::ints( $stored['page_ids'] );
	}

	/**
	 * Positive unique ids in ascending order.
	 *
	 * @param array<int, mixed> $ids Raw ids.
	 * @return int[]
	 */
	private static function ints( array $ids ): array {
		$clean = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$clean[ $id ] = $id;
			}
		}

		$clean = array_values( $clean );
		sort( $clean );

		return $clean;
	}
}
