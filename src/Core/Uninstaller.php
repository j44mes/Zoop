<?php
/**
 * Uninstall behaviour shared with uninstall.php.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Core;

use SEOStudio\Jobs\Scheduler;

/**
 * The flag drops tables and meta. Without it, only the option and scheduled jobs go.
 */
final class Uninstaller {
	public static function run(): void {
		$settings = get_option( Settings::OPTION, array() );
		$delete   = is_array( $settings ) && ! empty( $settings['uninstall_delete_data'] );
		self::removeOptionsAndJobs();
		if ( $delete ) {
			self::deleteStoredData();
		}
	}

	public static function removeOptionsAndJobs(): void {
		Scheduler::unschedule();
		delete_option( Settings::OPTION );
		delete_option( 'seostudio_detected_plugins' );
	}

	public static function deleteStoredData(): void {
		global $wpdb;

		$tables = array(
			$wpdb->prefix . 'seostudio_scores',
			$wpdb->prefix . 'seostudio_keyphrases',
			$wpdb->prefix . 'seostudio_audit_runs',
			$wpdb->prefix . 'seostudio_pages',
		);
		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table names.
		}

		foreach ( self::metaKeys() as $key ) {
			delete_metadata( 'post', 0, $key, '', true );
		}
	}

	/**
	 * Meta keys Zoop owns.
	 *
	 * @return string[]
	 */
	public static function metaKeys(): array {
		return array(
			'_seostudio_focus_keyphrase',
			'_seostudio_related_keyphrases',
			'_seostudio_synonyms',
			'_seostudio_title',
			'_seostudio_description',
			'_seostudio_canonical',
			'_seostudio_robots',
			'_seostudio_og_title',
			'_seostudio_og_description',
			'_seostudio_og_image',
			'_seostudio_cornerstone',
			'_seostudio_content_selector',
			'_seostudio_video_url',
			'_seostudio_is_news',
		);
	}
}
