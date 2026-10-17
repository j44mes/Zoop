<?php
/**
 * Shared post-meta lookups for importers.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Integrations\Importers;

/**
 * Read-only queries against another plugin's post meta.
 */
trait MetaQuery {
	/**
	 * Count posts that have any of these meta keys.
	 *
	 * @param string[] $keys
	 */
	protected function countAny( array $keys ): int {
		global $wpdb;

		if ( array() === $keys ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$sql          = "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) AND meta_value <> ''";
		$prepared     = $wpdb->prepare( $sql, $keys ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders match the key list.
		if ( ! is_string( $prepared ) ) {
			return 0;
		}

		return (int) $wpdb->get_var( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
	}

	/**
	 * Posts where the import would replace a value SEO Studio already holds.
	 *
	 * @param string[] $keys Source plugin meta keys.
	 */
	protected function countOverwrites( array $keys ): int {
		global $wpdb;

		$own      = array( '_seostudio_title', '_seostudio_description', '_seostudio_focus_keyphrase', '_seostudio_canonical', '_seostudio_robots' );
		$source   = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$target   = implode( ', ', array_fill( 0, count( $own ), '%s' ) );
		$sql      = "SELECT COUNT(DISTINCT source.post_id) FROM {$wpdb->postmeta} source
			INNER JOIN {$wpdb->postmeta} own ON own.post_id = source.post_id
			WHERE source.meta_key IN ({$source}) AND source.meta_value <> ''
			AND own.meta_key IN ({$target}) AND own.meta_value <> ''";
		$prepared = $wpdb->prepare( $sql, array( ...$keys, ...$own ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders match both key lists.
		if ( ! is_string( $prepared ) ) {
			return 0;
		}

		return (int) $wpdb->get_var( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
	}

	protected function countKey( string $key ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
				$key
			)
		);
	}

	/**
	 * Post IDs above the cursor that store one of these keys.
	 *
	 * @param string[] $keys
	 * @return int[]
	 */
	protected function idsWithKeys( array $keys, int $cursor, int $limit ): array {
		global $wpdb;

		if ( array() === $keys ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$sql          = "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) AND post_id > %d ORDER BY post_id ASC LIMIT %d";
		$prepared     = $wpdb->prepare( $sql, array( ...$keys, $cursor, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders match the key list.
		if ( ! is_string( $prepared ) ) {
			return array();
		}
		$ids = $wpdb->get_col( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}
}
