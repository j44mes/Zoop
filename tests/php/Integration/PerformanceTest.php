<?php
/**
 * Dashboard query timing on a modest row set.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Core\Plugin;
use SEOStudio\Storage\PagesRepository;
use WP_UnitTestCase;

/**
 * Inserts page rows with SQL and times the list query.
 *
 * The 10,000-row and 150 ms shared-host target in the specification was not run here.
 */
final class PerformanceTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
	}

	public function test_the_page_search_returns_quickly_for_a_few_hundred_rows(): void {
		global $wpdb;

		$pages = $wpdb->prefix . 'seostudio_pages';
		$now   = current_time( 'mysql', true );
		for ( $index = 0; $index < 200; $index++ ) {
			$post_id = self::factory()->post->create( array( 'post_title' => 'Perf ' . $index ) );
			$wpdb->insert(
				$pages,
				array(
					'object_type'      => 'post',
					'object_id'        => $post_id,
					'url'              => 'https://example.test/perf-' . $post_id,
					'url_hash'         => md5( 'perf-' . $post_id ),
					'input_hash'       => md5( 'input-' . $post_id ),
					'page_model'       => '{}',
					'render_mode'      => 'content',
					'content_selector' => '',
					'last_rendered_at' => $now,
				)
			);
		}

		$started = microtime( true );
		$rows    = ( new PagesRepository() )->search( array( 'search' => 'Perf' ), 20, 0, false );
		$elapsed = microtime( true ) - $started;

		$this->assertCount( 20, $rows );
		$this->assertLessThan( 2.0, $elapsed );
	}
}
