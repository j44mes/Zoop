<?php
/**
 * Capacity targets from the specification: 10,000 rows, a batch of 25, and batch memory.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Perf;

use SEOStudio\Core\Plugin;
use SEOStudio\Core\Settings;
use SEOStudio\Jobs\AuditBatch;
use SEOStudio\Storage\AuditRunsRepository;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Storage\Tables;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Timings on CI hardware, which is slower than a typical shared host only in disk latency.
 */
final class DashboardPerformanceTest extends WP_UnitTestCase {
	private const ROWS = 10000;

	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		do_action( 'init' );
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_the_dashboard_list_stays_under_150_ms_at_10000_rows(): void {
		$this->seed( self::ROWS );
		// The schema 3 upgrade fills the dashboard columns; seeding bypassed the write paths.
		$backfill = $this->timed( static fn () => ( new PagesRepository() )->backfillFocus() );

		$request = new WP_REST_Request( 'GET', '/seostudio/v1/pages' );
		$request->set_param( 'per_page', 20 );
		$request->set_param( 'orderby', 'score' );
		rest_do_request( $request ); // Warm the object and query caches as a second page view would.

		$query = $this->timed( static fn () => ( new PagesRepository() )->search( array( 'orderby' => 'score' ), 20, 0, true ) );
		$list  = $this->timed( static fn () => rest_do_request( $request ) );

		fwrite( STDERR, sprintf( "\nDashboard at %d rows: query %.1f ms, full request %.1f ms, upgrade backfill %.1f ms\n", self::ROWS, $query, $list, $backfill ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Report timings in the CI log.
		$this->assertLessThan( 150.0, $query, 'Dashboard query' );
		$this->assertLessThan( 1000.0, $list, 'Full dashboard request, including permission checks' );

		$data = rest_do_request( $request )->get_data();
		$this->assertSame( self::ROWS, $data['total'] );
		$this->assertSame( self::ROWS, $data['summary']['orange'] );
		$this->assertNotNull( $data['items'][0]['onpage']['score'], 'Backfilled scores reach the list.' );
	}

	public function test_a_batch_of_25_finishes_under_10_seconds_and_64_mb(): void {
		for ( $i = 0; $i < 25; ++$i ) {
			self::factory()->post->create(
				array(
					'post_status'  => 'publish',
					'post_title'   => 'Batch post ' . $i,
					'post_content' => str_repeat( '<!-- wp:paragraph --><p>Rank tracker body copy for the batch test.</p><!-- /wp:paragraph -->', 40 ),
				)
			);
		}
		$settings               = Settings::all();
		$settings['batch_size'] = 25;
		update_option( Settings::OPTION, $settings, true );

		$runs   = new AuditRunsRepository();
		$run_id = $runs->create( 'audit', 25 );
		$before = memory_get_usage();
		if ( function_exists( 'memory_reset_peak_usage' ) ) {
			memory_reset_peak_usage();
		}
		$seconds = $this->timed( static fn () => ( new AuditBatch() )->handle( $run_id ) ) / 1000;
		$peak    = ( memory_get_peak_usage() - $before ) / 1048576;

		fwrite( STDERR, sprintf( "Batch of 25: %.2f s, %.1f MB above baseline\n", $seconds, $peak ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Report timings in the CI log.
		$this->assertSame( 25, (int) $runs->find( $run_id )['processed'] );
		$this->assertLessThan( 10.0, $seconds );
		$this->assertLessThan( 64.0, $peak );
	}

	/**
	 * Milliseconds taken by one call.
	 */
	private function timed( callable $work ): float {
		$start = hrtime( true );
		$work();
		return ( hrtime( true ) - $start ) / 1e6;
	}

	/**
	 * Insert posts, focus keyphrases, page rows and scores in bulk. The factory would take minutes.
	 */
	private function seed( int $count ): void {
		global $wpdb;

		// Upgrade and uninstall tests run DDL, which commits their fixtures; start from empty tables.
		$wpdb->query( 'TRUNCATE TABLE ' . Tables::pages() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Internal table name.
		$wpdb->query( 'TRUNCATE TABLE ' . Tables::scores() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- As above.

		$author = get_current_user_id();
		$now    = current_time( 'mysql', true );
		$first  = (int) $wpdb->get_var( "SELECT COALESCE(MAX(ID), 0) + 1 FROM {$wpdb->posts}" );
		for ( $offset = 0; $offset < $count; $offset += 1000 ) {
			$posts = array();
			$meta  = array();
			$pages = array();
			$limit = min( 1000, $count - $offset );
			for ( $i = 0; $i < $limit; ++$i ) {
				$id      = $first + $offset + $i;
				$posts[] = $wpdb->prepare( '(%d, %d, %s, %s, %s, %s, %s, %s, %s, %s)', $id, $author, $now, $now, 'Body', 'Perf post ' . $id, 'publish', 'perf-post-' . $id, 'post', '' );
				$meta[]  = $wpdb->prepare( '(%d, %s, %s)', $id, '_seostudio_focus_keyphrase', 'rank tracker' );
				$url     = 'https://example.test/perf-post-' . $id . '/';
				$pages[] = $wpdb->prepare( "(%s, %d, %s, %s, '', '{}', 'content', '', %s)", 'post', $id, $url, md5( $url ), $now );
			}
			$wpdb->query( "INSERT INTO {$wpdb->posts} (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_status, post_name, post_type, post_excerpt) VALUES " . implode( ',', $posts ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Each row is prepared above.
			$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $meta ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- As above.
			$wpdb->query( 'INSERT INTO ' . Tables::pages() . ' (object_type, object_id, url, url_hash, input_hash, page_model, render_mode, content_selector, last_rendered_at) VALUES ' . implode( ',', $pages ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- As above.
		}

		$hash = md5( 'rank tracker' );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'INSERT INTO ' . Tables::scores() . " (page_id, keyphrase, keyphrase_hash, engine, score, denominator, rating, details, plugin_version, created_at)
				SELECT id, 'rank tracker', %s, 'onpage', FLOOR(RAND() * 100), 100, 'orange', '[]', 'perf', %s FROM " . Tables::pages(), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table names.
				$hash,
				$now
			)
		);
	}
}
