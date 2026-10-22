<?php
/**
 * Seed or clear the saved-score notice for the browser test.
 *
 * @package SEOStudio
 */

use SEOStudio\Core\LegacyScoreNotice;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Storage\Tables;

$mode = isset( $args[0] ) ? (string) $args[0] : 'plant';
if ( 'clear' === $mode ) {
	delete_option( LegacyScoreNotice::OPTION );
	delete_metadata( 'user', 0, LegacyScoreNotice::META, '', true );
	echo "ZOOP_NOTICE_CLEARED\n";
	return;
}

if ( 'failure' === $mode ) {
	global $wpdb;
	$post_id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_title = 'Saved score page' AND post_status = 'publish' ORDER BY ID DESC LIMIT 1" );
	$page    = ( new PagesRepository() )->findByObject( 'post', $post_id );
	if ( ! is_array( $page ) ) {
		echo "ZOOP_FAILURE_MISSING\n";
		return;
	}
	$wpdb->update(
		Tables::pages(),
		array(
			'last_error'           => "Couldn't check the published page. Your host returned a protection page (HTTP 202). Password protection or a bot check may be blocking Zoop.",
			'fetch_http_status'    => 202,
			'fetch_failure_reason' => 'protection_page',
			'fetch_attempted_at'   => current_time( 'mysql', true ),
			'last_fetched_at'      => '2026-10-21 12:00:00',
			'page_model'           => wp_json_encode( array( 'title_tag' => 'Saved score page', 'body_text' => 'Kept body.' ) ),
			'has_published_snapshot' => 1,
		),
		array( 'id' => (int) $page['id'] )
	);
	echo 'ZOOP_POST=' . $post_id . "\n";
	return;
}

$post_id = wp_insert_post(
	array(
		'post_status' => 'publish',
		'post_title'  => 'Saved score page',
		'post_author' => get_current_user_id() > 0 ? get_current_user_id() : 1,
	),
	true
);
if ( is_wp_error( $post_id ) ) {
	echo $post_id->get_error_message();
	return;
}

$pages   = new PagesRepository();
$page_id = $pages->save(
	array(
		'object_type' => 'post',
		'object_id'   => (int) $post_id,
		'url'         => (string) get_permalink( $post_id ),
		'page_model'  => array( 'title_tag' => 'Saved score page' ),
	)
);
global $wpdb;
$wpdb->update(
	Tables::pages(),
	array(
		'onpage_state'   => 'needs_reanalysis',
		'evidence_ready' => 0,
	),
	array( 'id' => $page_id )
);
$wpdb->insert(
	Tables::scores(),
	array(
		'page_id'        => $page_id,
		'keyphrase'      => 'saved phrase',
		'keyphrase_hash' => md5( 'saved phrase' ),
		'engine'         => 'onpage',
		'score'          => 20,
		'denominator'    => 80,
		'rating'         => 'red',
		'details'        => '{}',
		'plugin_version' => '0.5.0',
		'created_at'     => current_time( 'mysql', true ),
	)
);
LegacyScoreNotice::remember( array( $page_id ) );
echo 'ZOOP_POST=' . (int) $post_id . "\n";
echo "ZOOP_NOTICE_PLANTED\n";
