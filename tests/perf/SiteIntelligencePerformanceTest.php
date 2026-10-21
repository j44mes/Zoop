<?php
/**
 * Site intelligence graph cost at representative inventory sizes.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Perf;

use SEOStudio\Architecture\Graph;
use WP_UnitTestCase;

/**
 * Times the in-memory graph. It does not change the dashboard list query.
 */
final class SiteIntelligencePerformanceTest extends WP_UnitTestCase {
	public function test_graph_computation_reports_time_and_memory(): void {
		foreach ( array( 100, 1000, 10000 ) as $count ) {
			$measurement = $this->measure( $count );
			fwrite( STDERR, sprintf( "\nSite intelligence at %d pages: %.1f ms, %.1f MB\n", $count, $measurement['ms'], $measurement['mb'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Report timings in the CI log.
			$this->assertSame( $count, $measurement['inventory'] );
			$this->assertLessThan( 64.0, $measurement['mb'], 'Graph memory at ' . $count . ' pages' );
		}
		$large = $this->measure( 10000 );
		$this->assertLessThan( 5000.0, $large['ms'], 'Graph computation at 10000 pages' );
	}

	/**
	 * @return array{ms: float, mb: float, inventory: int}
	 */
	private function measure( int $count ): array {
		$pages = array();
		for ( $index = 1; $index <= $count; $index++ ) {
			$links = array();
			if ( $index > 1 ) {
				$links[] = array(
					'href' => '/page-' . ( $index - 1 ),
					'kind' => 'contextual',
				);
			}
			$pages[] = array(
				'id'         => 'post:' . $index,
				'post_id'    => $index,
				'title'      => 'Page ' . $index,
				'url'        => 'https://example.test/page-' . $index,
				'analysed'   => true,
				'link_fresh' => true,
				'links'      => $links,
			);
		}
		$before  = memory_get_usage( true );
		$start   = microtime( true );
		$result  = ( new Graph() )->evaluate(
			$pages,
			array(
				array(
					'href'   => '/page-1',
					'anchor' => 'Home',
				),
			),
			array(
				'home'              => 'https://example.test/',
				'front_id'          => 'post:1',
				'coverage_complete' => true,
				'missing'           => 0,
			)
		);
		$elapsed = ( microtime( true ) - $start ) * 1000;
		$used    = max( 0, memory_get_usage( true ) - $before ) / 1048576;

		return array(
			'ms'        => $elapsed,
			'mb'        => $used,
			'inventory' => (int) $result['report']['counts']['inventory'],
		);
	}
}
