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
		if ( $current >= 1 && self::tablesExist() ) {
			return;
		}

		( new Migration_001_Initial() )->up();
		$settings['schema_version'] = 1;
		update_option( Settings::OPTION, $settings, true );
	}

	private static function tablesExist(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'seostudio_pages';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}
}
