<?php
/**
 * Score history.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Storage;

use SEOStudio\Scoring\OnPage\Score;

/**
 * Reads and writes seostudio_scores.
 */
final class ScoresRepository {
	public function insert( int $pageId, string $keyphrase, Score $score, string $version ): int {
		global $wpdb;

		$wpdb->insert(
			Tables::scores(),
			array(
				'page_id'        => $pageId,
				'keyphrase'      => $keyphrase,
				'keyphrase_hash' => md5( mb_strtolower( $keyphrase, 'UTF-8' ) ),
				'engine'         => 'onpage',
				'score'          => $score->earned,
				'denominator'    => $score->denominator,
				'rating'         => $this->rating( $score ),
				'details'        => wp_json_encode( $score->toArray() ),
				'plugin_version' => $version,
				'created_at'     => current_time( 'mysql', true ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Store a content-analysis result from the admin worker.
	 *
	 * @param array<string, mixed> $details
	 */
	public function insertEngine( int $pageId, string $keyphrase, string $engine, int $score, int $denominator, string $rating, array $details ): int {
		global $wpdb;

		$wpdb->insert(
			Tables::scores(),
			array(
				'page_id'        => $pageId,
				'keyphrase'      => $keyphrase,
				'keyphrase_hash' => md5( mb_strtolower( $keyphrase, 'UTF-8' ) ),
				'engine'         => $engine,
				'score'          => $score,
				'denominator'    => $denominator,
				'rating'         => $rating,
				'details'        => wp_json_encode( $details ),
				'plugin_version' => defined( 'SEOSTUDIO_VERSION' ) ? SEOSTUDIO_VERSION : '0.2.0',
				'created_at'     => current_time( 'mysql', true ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Return the newest score for a keyphrase.
	 *
	 * @return array<string, mixed>|null
	 */
	public function latest( int $pageId, string $keyphrase, string $engine = 'onpage' ): ?array {
		global $wpdb;
		$table = Tables::scores();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE page_id = %d AND keyphrase_hash = %s AND engine = %s ORDER BY created_at DESC, id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				$pageId,
				md5( mb_strtolower( $keyphrase, 'UTF-8' ) ),
				$engine
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Latest score row for every keyphrase and engine on a page.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function latestForPage( int $pageId ): array {
		global $wpdb;

		$table = Tables::scores();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT scores.* FROM {$table} scores INNER JOIN (
					SELECT MAX(id) AS id FROM {$table} WHERE page_id = %d GROUP BY keyphrase_hash, engine
				) latest ON latest.id = scores.id",
				$pageId
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	private function rating( Score $score ): string {
		if ( 0 === $score->denominator ) {
			return 'grey';
		}
		$percent = (int) round( ( $score->earned / $score->denominator ) * 100 );
		if ( $percent >= 80 ) {
			return 'green';
		}
		if ( $percent >= 50 ) {
			return 'orange';
		}
		return 'red';
	}
}
