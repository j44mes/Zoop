<?php
/**
 * Removes Zoop data only when the owner opted in.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$settings = get_option( 'seostudio_settings', array() );
$delete   = is_array( $settings ) && ! empty( $settings['uninstall_delete_data'] );

if ( ! $delete ) {
	return;
}

global $wpdb;

delete_option( 'seostudio_settings' );

$tables = array(
	$wpdb->prefix . 'seostudio_scores',
	$wpdb->prefix . 'seostudio_keyphrases',
	$wpdb->prefix . 'seostudio_audit_runs',
	$wpdb->prefix . 'seostudio_pages',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

$meta_keys = array(
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
);

foreach ( $meta_keys as $key ) {
	delete_metadata( 'post', 0, $key, '', true );
}
