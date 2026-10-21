<?php
/**
 * Schema upgrades.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Core;

use SEOStudio\Storage\PagesRepository;

use SEOStudio\Storage\Migrations\Migration_001_Initial;

/**
 * Runs ordered migrations when the stored schema version is behind.
 */
final class Upgrader {
	public const CURRENT = 5;

	public static function maybeUpgrade(): void {
		$settings = Settings::all();
		$current  = (int) ( $settings['schema_version'] ?? 0 );
		if ( $current >= self::CURRENT && self::tablesExist() ) {
			return;
		}

		( new Migration_001_Initial() )->up();
		// Old rows were unconditionally marked indexable; invalidate that claim.
		if ( $current < 2 ) {
			global $wpdb;
			$table   = $wpdb->prefix . 'seostudio_pages';
			$altered = $wpdb->query( "ALTER TABLE {$table} MODIFY is_indexable tinyint(1) NULL DEFAULT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name. dbDelta does not reliably change nullability.
			if ( false === $altered ) {
				return;
			}
			$wpdb->query( "UPDATE {$table} SET is_indexable = NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
		}
		// Schema 3 keeps the focus keyphrase and its latest on-page score on the page row.
		if ( $current < 3 ) {
			( new PagesRepository() )->backfillFocus();
		}
		// Schema 4 adds the imported Search Console table through dbDelta above.
		// Schema 5 classifies cached on-page scores. Old rows are not evidence version 2.
		if ( $current < 5 ) {
			self::ensureOnPageState();
			( new PagesRepository() )->retireCachedScores();
		}
		$settings['schema_version'] = self::CURRENT;
		update_option( Settings::OPTION, $settings, true );
	}

	private static function ensureOnPageState(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'seostudio_pages';
		$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'onpage_state' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
		if ( 'onpage_state' === $found ) {
			return;
		}

		$wpdb->query( "ALTER TABLE {$table} ADD COLUMN onpage_state varchar(20) NOT NULL DEFAULT 'not_analysed'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
	}

	private static function tablesExist(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'seostudio_pages';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}
}
