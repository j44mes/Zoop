<?php
/**
 * Schema upgrades.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Core;

use SEOStudio\Storage\Migrations\Migration_001_Initial;

/**
 * Runs ordered migrations when the stored schema version is behind.
 */
final class Upgrader {
	public static function maybeUpgrade(): void {
		$settings = Settings::all();
		$current  = (int) ( $settings['schema_version'] ?? 0 );
		if ( $current >= 2 && self::tablesExist() ) {
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
		$settings['schema_version'] = 2;
		update_option( Settings::OPTION, $settings, true );
	}

	private static function tablesExist(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'seostudio_pages';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}
}
