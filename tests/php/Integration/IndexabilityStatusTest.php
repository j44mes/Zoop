<?php
/**
 * Indexability statuses stay distinct from a noindex directive.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Content\PageModel;
use SEOStudio\Core\Plugin;
use SEOStudio\Recommendations\PrioritiesReport;
use SEOStudio\Rest\PageRecord;
use SEOStudio\Scoring\OnPage\ScorePresentation;
use SEOStudio\Storage\Indexability;
use SEOStudio\Storage\Tables;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Password, privacy, site visibility, and robots directives.
 */
final class IndexabilityStatusTest extends WP_UnitTestCase {
	private int $public = 0;

	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		update_option( 'blog_public', '1' );
		$this->public = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Public page',
			)
		);
	}

	public function tear_down(): void {
		update_option( 'blog_public', '1' );
		parent::tear_down();
	}

	public function test_directive_statuses_and_visibility_stay_separate(): void {
		$live = $this->model( 'index, max-image-preview:none', '' );
		$this->assertSame( 'indexable', Indexability::describe( $this->public, $live )['status'] );
		$this->assertTrue( Indexability::forPost( $this->public, $live ) );
		$this->assertSame( 'Indexable', ScorePresentation::searchLabel( 'indexable' ) );

		$this->assertSame( 'noindex', Indexability::describe( $this->public, $this->model( 'noindex', '' ) )['status'] );
		$this->assertSame( 'noindex', Indexability::describe( $this->public, $this->model( 'none', '' ) )['status'] );
		$this->assertSame( 'noindex', Indexability::describe( $this->public, $this->model( 'noindex,nofollow', '' ) )['status'] );
		$this->assertSame( 'noindex', Indexability::describe( $this->public, $this->model( 'googlebot: noindex', '' ) )['status'] );
		$conflict = Indexability::describe( $this->public, $this->model( 'index', 'noindex' ) );
		$this->assertSame( 'noindex', $conflict['status'] );
		$this->assertSame( 'http', $conflict['source'] );
		$this->assertFalse( Indexability::forPost( $this->public, $this->model( 'noindex, follow', '' ) ) );

		$password = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
				'post_title'    => 'Password page',
			)
		);
		$private  = self::factory()->post->create(
			array(
				'post_status' => 'private',
				'post_title'  => 'Private page',
			)
		);
		$this->assertSame( 'not_public', Indexability::describe( $password, $this->model( 'noindex', '' ) )['status'] );
		$this->assertSame( 'not_public', Indexability::describe( $private, $live )['status'] );
		$this->assertNotSame( 'Hidden from search', ScorePresentation::searchLabel( 'not_public' ) );

		update_option( 'blog_public', '0' );
		$discouraged = Indexability::describe( $this->public, $live );
		$this->assertSame( 'site_discouraged', $discouraged['status'] );
		$this->assertSame( 'blog_public', $discouraged['source'] );
		$this->assertNotSame( 'Hidden from search', ScorePresentation::searchLabel( 'site_discouraged' ) );
		$this->assertNotSame( 'noindex', $discouraged['status'] );
		$also_hidden = Indexability::describe( $this->public, $this->model( 'noindex', '' ) );
		$this->assertSame( 'site_discouraged', $also_hidden['status'] );
		$this->assertSame( 'blog_public', $also_hidden['source'] );
		$this->assertSame( 'not_public', Indexability::describe( $password, $this->model( 'noindex', '' ) )['status'] );
		update_option( 'blog_public', '1' );
		$this->assertSame( 'noindex', Indexability::describe( $this->public, $this->model( 'noindex', '' ) )['status'] );

		$unknown = Indexability::describe( $this->public, new PageModel( renderMode: 'live', metadataStatus: 'estimated' ) );
		$this->assertSame( 'unobserved', $unknown['status'] );
		$this->assertSame( 'Indexability has not been checked', ScorePresentation::searchLabel( 'unobserved' ) );
	}

	public function test_one_hundred_discouraged_pages_do_not_create_individual_findings(): void {
		Plugin::init();
		do_action( 'rest_api_init' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		update_option( 'blog_public', '1' );

		global $wpdb;
		$now   = current_time( 'mysql', true );
		$first = (int) $wpdb->get_var( "SELECT COALESCE(MAX(ID), 0) + 1 FROM {$wpdb->posts}" );
		$posts = array();
		$pages = array();
		for ( $index = 0; $index < 100; $index++ ) {
			$id      = $first + $index;
			$posts[] = $wpdb->prepare( '(%d, %d, %s, %s, %s, %s, %s, %s, %s, %s)', $id, $admin, $now, $now, 'Body', 'Observed page ' . $id, 'publish', 'observed-' . $id, 'post', '' );
			$url     = 'https://example.test/observed-' . $id . '/';
			$pages[] = $wpdb->prepare( "(%s, %d, %s, %s, %s, '{}', 'live', '', %s, 'current', 1, 80, 100, 'green', 'noindex', 'meta', 1)", 'post', $id, $url, md5( $url ), md5( 'in-' . $id ), $now );
		}
		$wpdb->query( "INSERT INTO {$wpdb->posts} (ID, post_author, post_date, post_date_gmt, post_content, post_title, post_status, post_name, post_type, post_excerpt) VALUES " . implode( ',', $posts ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Each row is prepared above.
		$wpdb->query( 'INSERT INTO ' . Tables::pages() . ' (object_type, object_id, url, url_hash, input_hash, page_model, render_mode, content_selector, last_rendered_at, onpage_state, evidence_ready, onpage_score, onpage_denominator, onpage_rating, index_status, index_source, has_published_snapshot) VALUES ' . implode( ',', $pages ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Each row is prepared above.

		$this->assertSame( 100, ( new PageRecord() )->count( array( 'visibility' => 'noindex' ) ) );
		update_option( 'blog_public', '0' );
		$this->assertSame( 0, ( new PageRecord() )->count( array( 'visibility' => 'noindex' ) ) );
		$list = rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/pages' ) );
		$this->assertTrue( $list->get_data()['summary']['search_discouraged'] );
		$report = ( new PrioritiesReport() )->report();
		$this->assertNotContains( 'visibility', array_column( $report['items'], 'kind' ) );
		$this->assertSame( 'site_discouraged', Indexability::describe( $first, $this->model( 'noindex', '' ) )['status'] );

		update_option( 'blog_public', '1' );
		$this->assertSame( 'noindex', Indexability::describe( $first, $this->model( 'noindex', '' ) )['status'] );
		$this->assertSame( 0, ( new PageRecord() )->count( array( 'visibility' => 'noindex' ) ) );
		$wpdb->update(
			Tables::pages(),
			array(
				'evidence_ready'     => 1,
				'onpage_state'       => 'current',
				'index_status'       => 'noindex',
				'index_source'       => 'meta',
				'onpage_score'       => 80,
				'onpage_denominator' => 100,
				'onpage_rating'      => 'green',
			),
			array( 'object_id' => $first )
		);
		$this->assertSame( 1, ( new PageRecord() )->count( array( 'visibility' => 'noindex' ) ) );
		$again = ( new PrioritiesReport() )->report();
		$found = null;
		foreach ( $again['items'] as $item ) {
			if ( 'visibility' === ( $item['kind'] ?? '' ) ) {
				$found = $item;
			}
		}
		$this->assertIsArray( $found );
		$this->assertSame( '1 page is hidden from search.', $found['title'] );
		$this->assertSame( array(), $found['pages'] );
		$stale = new WP_REST_Request( 'GET', '/seostudio/v1/pages' );
		$stale->set_param( 'score_state', 'needs_reanalysis' );
		$stale->set_param( 'per_page', 1 );
		$stale_data = rest_do_request( $stale )->get_data();
		$this->assertGreaterThanOrEqual( 99, $stale_data['total'] );
		$this->assertCount( 1, $stale_data['items'] );
	}

	private function model( string $meta, string $http ): PageModel {
		return new PageModel(
			renderMode: 'live',
			metadataStatus: 'observed_html',
			robots: trim( $meta . ', ' . $http, ', ' ),
			robotsMeta: $meta,
			robotsHttp: $http
		);
	}
}
