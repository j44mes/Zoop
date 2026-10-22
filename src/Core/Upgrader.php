<?php
/**
 * Schema upgrades.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Core;

use SEOStudio\Storage\Migrations\Migration_001_Initial;
use SEOStudio\Storage\PagesRepository;

/**
 * Runs ordered migrations when the stored schema version is behind.
 */
final class Upgrader {
	public const CURRENT = 8;

	public static function maybeUpgrade(): void {
		$settings = Settings::all();
		$current  = (int) ( $settings['schema_version'] ?? 0 );
		if ( $current >= self::CURRENT && self::tablesExist() ) {
			RulesetApplier::synchronise();
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
			LegacyScoreNotice::captureUpgrade();
			( new PagesRepository() )->retireCachedScores();
		}
		// Schema 6 stores readiness on the page row. Previously verified live rows
		// wait for a new published-page check. Their snapshots are kept.
		if ( $current < 6 ) {
			self::ensureReadinessColumns();
			( new PagesRepository() )->retireUnverifiedLiveScores();
		}
		// Schema 7 adds no columns. Retire incompatible scores before the settings
		// option is saved, because that save also marks published snapshots stale.
		// A failed retirement leaves the schema behind so the next load can retry
		// while the current scores are still current.
		if ( self::tablesExist() && ! RulesetApplier::synchronise() ) {
			return;
		}
		// Schema 8 records why the latest published-page fetch failed. Older snapshots stay.
		if ( $current < 8 && self::tablesExist() ) {
			self::ensureFetchDiagnostics();
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

	private static function ensureReadinessColumns(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'seostudio_pages';
		$columns = array(
			'evidence_ready'         => 'tinyint(1) NOT NULL DEFAULT 0',
			'checks_evaluated'       => 'smallint(6) NOT NULL DEFAULT 0',
			'checks_applicable'      => 'smallint(6) NOT NULL DEFAULT 0',
			'index_status'           => "varchar(20) NOT NULL DEFAULT 'unobserved'",
			'index_source'           => "varchar(20) NOT NULL DEFAULT ''",
			'missing_evidence'       => "varchar(80) NOT NULL DEFAULT ''",
			'verified_source_hash'   => "char(32) NOT NULL DEFAULT ''",
			'has_published_snapshot' => 'tinyint(1) NOT NULL DEFAULT 0',
			'verified_at'            => 'datetime NULL',
		);
		foreach ( $columns as $name => $definition ) {
			$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
			if ( $name === $found ) {
				continue;
			}
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$name} {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table and a fixed column definition.
		}
	}

	private static function ensureFetchDiagnostics(): void {
		global $wpdb;

		$table   = $wpdb->prefix . 'seostudio_pages';
		$columns = array(
			'fetch_http_status'    => 'smallint(6) NULL',
			'fetch_failure_reason' => "varchar(40) NOT NULL DEFAULT ''",
			'fetch_attempted_at'   => 'datetime NULL',
		);
		foreach ( $columns as $name => $definition ) {
			$found = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
			if ( $name === $found ) {
				continue;
			}
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$name} {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table and a fixed column definition.
		}
	}

	private static function tablesExist(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'seostudio_pages';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}
}
