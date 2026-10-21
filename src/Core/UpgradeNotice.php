<?php
/**
 * Ruleset-retirement notice for pages the current user can still check.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Core;

use SEOStudio\Rest\PageRecord;
use SEOStudio\Storage\Tables;

/**
 * The notice is per ruleset generation. Dismissal is per user and does not record migration success.
 */
final class UpgradeNotice {
	public const OPTION  = 'seostudio_ruleset_retirement_notice';
	public const PENDING = 'seostudio_ruleset_retirement_pending';
	public const META    = 'seostudio_dismissed_ruleset_notice';

	/**
	 * Remember pages that were definitive before this retirement attempt.
	 *
	 * An empty capture does not clear an earlier attempt. A failed run can resume
	 * after evidence_ready has already been withheld.
	 *
	 * @param int[] $pageIds Page ids that were current and evidence-ready.
	 */
	public static function rememberDefinitive( array $pageIds ): void {
		$pageIds = self::ints( $pageIds );
		if ( array() === $pageIds ) {
			return;
		}

		$merged = self::ints( array_merge( self::pendingIds(), $pageIds ) );
		update_option( self::PENDING, $merged, false );
	}

	/**
	 * Store a notice when this completed retirement changed previously definitive scores.
	 *
	 * An empty result does not remove a notice from an earlier generation.
	 */
	public static function recordRetired( int $ruleset ): void {
		$pending = self::pendingIds();
		if ( array() === $pending ) {
			return;
		}

		$affected = self::idsStillRetired( $pending );
		delete_option( self::PENDING );
		if ( array() === $affected ) {
			return;
		}

		$carried = self::idsStillRetired( self::noticeIds() );
		update_option(
			self::OPTION,
			array(
				'ruleset'  => $ruleset,
				'page_ids' => self::ints( array_merge( $affected, $carried ) ),
			),
			false
		);
	}

	/**
	 * Page ids from the stored retirement that still need a ruleset check.
	 *
	 * @return int[]
	 */
	public static function outstandingIds(): array {
		return self::idsStillRetired( self::noticeIds() );
	}

	/**
	 * Banner payload, or null when this user should not see it.
	 *
	 * @return array{ruleset: int, count: int, message: string, review_url: string}|null
	 */
	public static function payload(): ?array {
		$ruleset = self::storedRuleset();
		if ( $ruleset < 1 ) {
			return null;
		}

		$dismissed = (int) get_user_meta( get_current_user_id(), self::META, true );
		if ( $dismissed === $ruleset ) {
			return null;
		}

		$count = ( new PageRecord() )->count( array( 'score_state' => 'ruleset_retired' ) );
		if ( $count < 1 ) {
			return null;
		}

		return array(
			'ruleset'    => $ruleset,
			'count'      => $count,
			'message'    => self::message( $count ),
			'review_url' => 'admin.php?page=seostudio&section=pages&score_state=ruleset_retired',
		);
	}

	/**
	 * Hide the current generation for this user only.
	 */
	public static function dismiss(): void {
		$ruleset = self::storedRuleset();
		if ( $ruleset < 1 || get_current_user_id() < 1 ) {
			return;
		}

		update_user_meta( get_current_user_id(), self::META, $ruleset );
	}

	/**
	 * Sentence shown on the Zoop dashboard. The version name is the product line, not a stable tag.
	 */
	public static function message( int $count ): string {
		$count = max( 0, $count );

		return sprintf(
			/* translators: %d: number of pages the current user can still check after a scoring-rules update. */
			_n(
				'Zoop 0.6 uses updated scoring rules, so %d page needs checking again. Your previous scores have been kept.',
				'Zoop 0.6 uses updated scoring rules, so %d pages need checking again. Your previous scores have been kept.',
				$count,
				'seo-studio'
			),
			$count
		);
	}

	/**
	 * Stored ids that still carry the ruleset retirement marker.
	 *
	 * @param int[] $pageIds Candidate page ids.
	 * @return int[]
	 */
	private static function idsStillRetired( array $pageIds ): array {
		global $wpdb;

		$pageIds = self::ints( $pageIds );
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
					"SELECT id FROM {$pages} WHERE missing_evidence = 'ruleset' AND id IN ({$in})",
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
	 * Page ids captured before a retirement finished.
	 *
	 * @return int[]
	 */
	private static function pendingIds(): array {
		$stored = get_option( self::PENDING, array() );
		return is_array( $stored ) ? self::ints( $stored ) : array();
	}

	/**
	 * Page ids saved on the current notice.
	 *
	 * @return int[]
	 */
	private static function noticeIds(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) || ! isset( $stored['page_ids'] ) || ! is_array( $stored['page_ids'] ) ) {
			return array();
		}

		return self::ints( $stored['page_ids'] );
	}

	private static function storedRuleset(): int {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return 0;
		}

		return (int) ( $stored['ruleset'] ?? 0 );
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
