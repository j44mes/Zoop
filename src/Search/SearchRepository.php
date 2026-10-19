<?php
/**
 * Imported Search Console rows.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Search;

use SEOStudio\Architecture\UrlKey;
use SEOStudio\Storage\Tables;

/**
 * Replaces the previous import. The rows are a file the user supplied.
 */
final class SearchRepository {
	public const OPTION = 'seostudio_search_import';

	/**
	 * Replace every stored row with one import.
	 *
	 * @param list<array{query: string, page: string, clicks: int, impressions: int, position: float|null}> $rows
	 */
	public function replace( array $rows, int $skipped ): void {
		global $wpdb;

		$table = Tables::searchRows();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
		$now = current_time( 'mysql', true );
		foreach ( $rows as $row ) {
			$wpdb->insert(
				$table,
				array(
					'query_text'  => $row['query'],
					'page_url'    => mb_substr( $row['page'], 0, 2048 ),
					'clicks'      => $row['clicks'],
					'impressions' => $row['impressions'],
					'position'    => $row['position'],
					'imported_at' => $now,
				)
			);
		}
		update_option(
			self::OPTION,
			array(
				'skipped'     => $skipped,
				'imported_at' => $now,
				'rows'        => count( $rows ),
			),
			false
		);
	}

	/**
	 * Summary of the current import.
	 *
	 * @return array<string, mixed>
	 */
	public function summary(): array {
		global $wpdb;

		$table = Tables::searchRows();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $found !== $table ) {
			return $this->emptySummary();
		}
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
		$top   = $wpdb->get_results( "SELECT query_text, page_url, clicks, impressions, position FROM {$table} ORDER BY impressions DESC, clicks DESC, id ASC LIMIT 20", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
		$meta  = get_option( self::OPTION, array() );
		$meta  = is_array( $meta ) ? $meta : array();
		$rows  = array();
		foreach ( is_array( $top ) ? $top : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = array(
				'query'       => (string) $row['query_text'],
				'page'        => (string) $row['page_url'],
				'clicks'      => (int) $row['clicks'],
				'impressions' => (int) $row['impressions'],
				'position'    => null === $row['position'] ? null : (float) $row['position'],
			);
		}

		$coverage = $this->pageCoverage();

		return array(
			'connected'         => false,
			'rows'              => $count,
			'rows_with_page'    => $coverage['with_page'],
			'rows_without_page' => $coverage['without_page'],
			'skipped'           => (int) ( $meta['skipped'] ?? 0 ),
			'imported_at'       => (string) ( $meta['imported_at'] ?? '' ),
			'top'               => $rows,
			'note'              => __( 'This is a file you imported. Zoop is not connected to Search Console.', 'seo-studio' ),
		);
	}

	/**
	 * How many stored rows name a usable page address.
	 *
	 * @return array{with_page: int, without_page: int}
	 */
	private function pageCoverage(): array {
		global $wpdb;

		$table = Tables::searchRows();
		$urls  = $wpdb->get_col( "SELECT page_url FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
		$with  = 0;
		$total = 0;
		foreach ( is_array( $urls ) ? $urls : array() as $url ) {
			++$total;
			$page = (string) $url;
			if ( '' !== UrlKey::identity( UrlKey::absolute( $page, $page ) ) ) {
				++$with;
			}
		}

		return array(
			'with_page'    => $with,
			'without_page' => $total - $with,
		);
	}

	/**
	 * Every stored query row, highest impressions first.
	 *
	 * @return list<array{query: string, page: string, clicks: int, impressions: int, position: float|null}>
	 */
	public function allRows(): array {
		global $wpdb;

		$table = Tables::searchRows();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $found !== $table ) {
			return array();
		}
		$stored = $wpdb->get_results( "SELECT query_text, page_url, clicks, impressions, position FROM {$table} ORDER BY impressions DESC, clicks DESC, id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
		$rows   = array();
		foreach ( is_array( $stored ) ? $stored : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$rows[] = array(
				'query'       => (string) $row['query_text'],
				'page'        => (string) $row['page_url'],
				'clicks'      => (int) $row['clicks'],
				'impressions' => (int) $row['impressions'],
				'position'    => null === $row['position'] ? null : (float) $row['position'],
			);
		}
		return $rows;
	}

	/**
	 * Summary before any file has been imported.
	 *
	 * @return array<string, mixed>
	 */
	private function emptySummary(): array {
		return array(
			'connected'         => false,
			'rows'              => 0,
			'rows_with_page'    => 0,
			'rows_without_page' => 0,
			'skipped'           => 0,
			'imported_at'       => '',
			'top'               => array(),
			'note'              => __( 'No Search Console file has been imported. Zoop is not connected to Search Console.', 'seo-studio' ),
		);
	}
}
