<?php
/**
 * Keyphrase index.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Storage;

/**
 * Tracks which posts use a keyphrase.
 */
final class KeyphrasesIndex {
	/**
	 * Rebuild the index from saved meta; primary wins when a phrase occurs twice.
	 */
	public function syncPost( int $postId ): void {
		global $wpdb;
		$this->deleteObject( $postId );
		$primary = get_post_meta( $postId, '_seostudio_focus_keyphrase', true );
		$related = get_post_meta( $postId, '_seostudio_related_keyphrases', true );
		foreach ( is_array( $related ) ? $related : array() as $entry ) {
			$phrase = is_array( $entry ) ? ( $entry['keyphrase'] ?? '' ) : '';
			if ( ! is_string( $phrase ) || '' === trim( $phrase ) ) {
				continue;
			}
			$wpdb->replace(
				Tables::keyphrases(),
				array(
					'keyphrase_hash' => md5( mb_strtolower( $phrase, 'UTF-8' ) ),
					'object_type'    => 'post',
					'object_id'      => $postId,
					'is_primary'     => 0,
				)
			);
		}
		$this->replacePrimary( $postId, is_string( $primary ) ? $primary : '' );
	}

	public function replacePrimary( int $postId, string $keyphrase ): void {
		global $wpdb;

		$table = Tables::keyphrases();
		$wpdb->delete(
			$table,
			array(
				'object_type' => 'post',
				'object_id'   => $postId,
				'is_primary'  => 1,
			)
		);

		if ( '' === trim( $keyphrase ) ) {
			return;
		}

		$wpdb->replace(
			$table,
			array(
				'keyphrase_hash' => md5( mb_strtolower( $keyphrase, 'UTF-8' ) ),
				'object_type'    => 'post',
				'object_id'      => $postId,
				'is_primary'     => 1,
			)
		);
	}

	/**
	 * Remove every index row for a deleted post.
	 */
	public function deleteObject( int $postId, string $type = 'post' ): void {
		global $wpdb;

		$table = Tables::keyphrases();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $found !== $table ) {
			return;
		}

		$wpdb->delete(
			$table,
			array(
				'object_type' => $type,
				'object_id'   => $postId,
			)
		);
	}

	/**
	 * Rows for a keyphrase.
	 *
	 * @return array<int, array{post_id: int, is_primary: bool}>
	 */
	public function rowsFor( string $keyphrase ): array {
		global $wpdb;

		$table = Tables::keyphrases();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_id, is_primary FROM {$table} WHERE keyphrase_hash = %s AND object_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				md5( mb_strtolower( $keyphrase, 'UTF-8' ) ),
				'post'
			),
			ARRAY_A
		);

		$found = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$found[] = array(
				'post_id'    => (int) $row['object_id'],
				'is_primary' => ! empty( $row['is_primary'] ),
			);
		}

		return $found;
	}

	/**
	 * Post ids for.
	 *
	 * @return int[]
	 */
	public function postIdsFor( string $keyphrase ): array {
		global $wpdb;
		$table = Tables::keyphrases();
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT object_id FROM {$table} WHERE keyphrase_hash = %s AND object_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				md5( mb_strtolower( $keyphrase, 'UTF-8' ) ),
				'post'
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}
}
