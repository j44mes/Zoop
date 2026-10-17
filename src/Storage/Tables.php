<?php
/**
 * Custom table definitions.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Storage;

/**
 * Schema installed with dbDelta.
 */
final class Tables {
	public static function pages(): string {
		global $wpdb;
		return $wpdb->prefix . 'seostudio_pages';
	}

	public static function scores(): string {
		global $wpdb;
		return $wpdb->prefix . 'seostudio_scores';
	}

	public static function keyphrases(): string {
		global $wpdb;
		return $wpdb->prefix . 'seostudio_keyphrases';
	}

	public static function auditRuns(): string {
		global $wpdb;
		return $wpdb->prefix . 'seostudio_audit_runs';
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$pages   = self::pages();
		$scores  = self::scores();
		$keys    = self::keyphrases();
		$runs    = self::auditRuns();

		$sql = array(
			"CREATE TABLE {$pages} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				object_type varchar(20) NOT NULL DEFAULT 'post',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				url varchar(2048) NOT NULL DEFAULT '',
				url_hash char(32) NOT NULL DEFAULT '',
				input_hash char(32) NOT NULL DEFAULT '',
				page_model longtext NULL,
				render_mode varchar(20) NOT NULL DEFAULT 'content',
				content_selector varchar(255) NOT NULL DEFAULT '',
				last_rendered_at datetime NULL,
				last_fetched_at datetime NULL,
				last_error text NULL,
				is_indexable tinyint(1) NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY url_hash (url_hash),
				KEY object_lookup (object_type, object_id),
				KEY last_rendered_at (last_rendered_at)
			) {$charset};",
			"CREATE TABLE {$scores} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				page_id bigint(20) unsigned NOT NULL DEFAULT 0,
				keyphrase varchar(255) NOT NULL DEFAULT '',
				keyphrase_hash char(32) NOT NULL DEFAULT '',
				engine varchar(20) NOT NULL DEFAULT 'onpage',
				score smallint(6) NOT NULL DEFAULT 0,
				denominator smallint(6) NOT NULL DEFAULT 100,
				rating varchar(20) NOT NULL DEFAULT 'grey',
				details longtext NULL,
				plugin_version varchar(16) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY page_keyphrase (page_id, keyphrase_hash, engine, created_at),
				KEY score (score)
			) {$charset};",
			"CREATE TABLE {$keys} (
				keyphrase_hash char(32) NOT NULL DEFAULT '',
				object_type varchar(20) NOT NULL DEFAULT 'post',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				is_primary tinyint(1) NOT NULL DEFAULT 1,
				PRIMARY KEY  (keyphrase_hash, object_type, object_id)
			) {$charset};",
			"CREATE TABLE {$runs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				type varchar(20) NOT NULL DEFAULT 'audit',
				status varchar(20) NOT NULL DEFAULT 'queued',
				total int(11) NOT NULL DEFAULT 0,
				processed int(11) NOT NULL DEFAULT 0,
				failed int(11) NOT NULL DEFAULT 0,
				cursor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				started_at datetime NULL,
				finished_at datetime NULL,
				report longtext NULL,
				PRIMARY KEY  (id),
				KEY status (status)
			) {$charset};",
		);

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}
