<?php
/**
 * Architecture counts stay silent when the evidence does not support them.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Architecture\SiteSurvey;
use SEOStudio\Content\PageModel;
use SEOStudio\Core\Plugin;
use SEOStudio\Jobs\PostTypes;
use SEOStudio\Storage\PagesRepository;
use WP_UnitTestCase;

/**
 * Incomplete coverage must not invent an orphan total or a reachability total.
 */
final class ArchitectureTruthTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		Plugin::init();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'permalink_structure', '/%postname%/' );
		flush_rewrite_rules( false );
		add_filter( 'pre_get_block_templates', array( $this, 'empty_block_templates' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_get_block_templates', array( $this, 'empty_block_templates' ), 10 );
		parent::tear_down();
	}

	/**
	 * @param mixed $templates Previous short-circuit value.
	 * @param mixed $query     Template query.
	 * @param mixed $type      Template type.
	 * @return array<int, object>
	 */
	public function empty_block_templates( $templates, $query, $type ): array {
		unset( $templates, $query, $type );
		return array();
	}

	public function test_stored_analyses_with_incomplete_live_evidence_are_not_called_unanalysed(): void {
		$ids = $this->publishedIds();
		while ( count( $ids ) < 26 ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_title'  => 'Analysed ' . count( $ids ),
				)
			);
		}
		foreach ( $ids as $post_id ) {
			$this->store( $post_id, 'content', array() );
		}

		$report = ( new SiteSurvey() )->report();
		$text   = $this->text( $report );

		$this->assertGreaterThanOrEqual( 26, $report['counts']['analysed'] );
		$this->assertSame( 0, $report['coverage']['reasons']['unanalysed'] );
		$this->assertGreaterThanOrEqual( 26, $report['coverage']['reasons']['content_only'] );
		$this->assertNull( $report['counts']['orphans'] );
		$this->assertNull( $report['counts']['unreachable'] );
		$this->assertStringContainsString( 'not counted yet', $this->explanation( $report ) );
		$this->assertStringContainsString( 'without published-link verification', $text );
		$this->assertStringContainsString( 'does not restore published', $text );
		$this->assertStringNotContainsString( 'not been analysed', $text );
		$this->assertStringNotContainsString( "haven't been analysed", $text );
		$this->assertStringContainsString( 'Depth is not known yet', $report['depth_explanation'] );
	}

	public function test_a_complete_graph_counts_missing_entrypoint_paths(): void {
		$front = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Front door',
			)
		);
		$linked = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Reached page',
			)
		);
		$template = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Template page',
			)
		);
		$orphan = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'No path page',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );
		$links = array(
			array(
				'href'   => (string) get_permalink( $linked ),
				'anchor' => 'Reached',
				'kind'   => 'contextual',
			),
			array(
				'href'   => (string) get_permalink( $template ),
				'anchor' => 'Template',
				'kind'   => 'template',
			),
		);
		foreach ( $this->publishedIds() as $post_id ) {
			$this->store( $post_id, 'live', (int) $post_id === (int) $front ? $links : array() );
		}

		$report = ( new SiteSurvey() )->report();
		$by_id  = array();
		foreach ( ( new SiteSurvey() )->evaluated()['pages'] as $page ) {
			if ( is_array( $page ) ) {
				$by_id[ (int) $page['post_id'] ] = $page;
			}
		}

		$this->assertTrue( $report['coverage']['complete'] );
		$this->assertIsInt( $report['counts']['unreachable'] );
		$this->assertGreaterThan( 0, $report['counts']['unreachable'] );
		$this->assertSame( 'orphan', $by_id[ $orphan ]['status'] );
		$this->assertNull( $by_id[ $orphan ]['depth'] );
		$this->assertSame( 'template_only', $by_id[ $template ]['status'] );
		$this->assertNotNull( $by_id[ $template ]['depth'] );
		$this->assertNotSame( 'orphan', $by_id[ $template ]['status'] );
		$this->assertNotSame( 'unknown', $by_id[ $linked ]['status'] );
		$this->assertStringContainsString( 'modelled entry points', $report['depth_explanation'] );
		$this->assertStringContainsString( 'search engines', $report['depth_explanation'] );
		$this->assertSame( array(), $report['unverified'] );
	}

	public function test_unanalysed_pages_name_the_site_audit_and_not_a_link_repair(): void {
		self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Never analysed',
			)
		);
		$report = ( new SiteSurvey() )->report();
		$text   = $this->text( $report );

		$this->assertGreaterThan( 0, $report['coverage']['reasons']['unanalysed'] );
		$this->assertNull( $report['counts']['orphans'] );
		$this->assertNull( $report['counts']['unreachable'] );
		$this->assertStringContainsString( 'not been analysed', $text );
		$this->assertStringContainsString( 'Run a site audit', $text );
		$this->assertStringContainsString( 'does not restore published-link evidence', $text );
		$this->assertStringContainsString( 'Depth is not known yet', $report['depth_explanation'] );
	}

	public function test_stale_published_links_ask_for_another_published_check(): void {
		$ids = $this->publishedIds();
		if ( array() === $ids ) {
			$ids[] = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Stale source' ) );
		}
		foreach ( $ids as $post_id ) {
			$this->store( $post_id, 'live', array() );
		}
		wp_update_post(
			array(
				'ID'           => (int) $ids[0],
				'post_content' => '<p>Changed after the published-link check.</p>',
			)
		);

		$report = ( new SiteSurvey() )->report();
		$text   = $this->text( $report );

		$this->assertSame( 0, $report['coverage']['reasons']['unanalysed'] );
		$this->assertGreaterThan( 0, $report['coverage']['reasons']['stale_links'] );
		$this->assertNull( $report['counts']['unreachable'] );
		$this->assertStringContainsString( 'stale published-link', $text );
		$this->assertStringContainsString( 'Check the published pages', $text );
		$this->assertStringNotContainsString( 'not been analysed', $text );
		$this->assertStringContainsString( 'does not restore published links', $text );
	}

	public function test_incomplete_navigation_coverage_withholds_depth_and_orphans(): void {
		remove_filter( 'pre_get_block_templates', array( $this, 'empty_block_templates' ), 10 );
		add_filter(
			'pre_get_block_templates',
			static function (): array {
				return array(
					(object) array(
						'content' => '<!-- wp:navigation {"overlayMenu":"never"} /-->',
					),
				);
			},
			10,
			3
		);
		try {
			self::factory()->post->create( array( 'post_title' => 'Fresh orphan', 'post_status' => 'publish' ) );
			foreach ( $this->publishedIds() as $post_id ) {
				$this->store( $post_id, 'live', array() );
			}
			if ( array() === $this->publishedIds() ) {
				$created = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Navigation limited' ) );
				$this->store( $created, 'live', array() );
			}
			$report = ( new SiteSurvey() )->report();
			$text   = $this->text( $report );

			$this->assertSame( 0, $report['coverage']['reasons']['unanalysed'] );
			$this->assertSame( 0, $report['coverage']['needs_published_check'] );
			$this->assertFalse( $report['coverage']['reasons']['navigation_limited'] );
			$this->assertTrue( $report['coverage']['complete'] );
			$this->assertIsInt( $report['counts']['orphans'] );
			$this->assertGreaterThan( 0, $report['counts']['orphans'] );
			$this->assertIsInt( $report['counts']['unreachable'] );
			$this->assertStringNotContainsString( 'not been analysed', $text );
		} finally {
			remove_all_filters( 'pre_get_block_templates' );
		}
	}

	public function test_partial_published_checks_withhold_the_orphan_count(): void {
		remove_filter( 'pre_get_block_templates', array( $this, 'empty_block_templates' ), 10 );
		add_filter(
			'pre_get_block_templates',
			static function (): array {
				return array(
					(object) array(
						'content' => '<!-- wp:navigation {"overlayMenu":"never"} /-->',
					),
				);
			},
			10,
			3
		);
		try {
			$pending = self::factory()->post->create( array( 'post_title' => 'Still unchecked', 'post_status' => 'publish' ) );
			foreach ( $this->publishedIds() as $post_id ) {
				if ( (int) $post_id === (int) $pending ) {
					continue;
				}
				$this->store( $post_id, 'live', array() );
			}
			$report = ( new SiteSurvey() )->report();
			$this->assertGreaterThan( 0, $report['coverage']['needs_published_check'] );
			$this->assertNull( $report['counts']['orphans'] );
			$this->assertFalse( $report['coverage']['complete'] );
			$this->assertStringContainsString( 'navigation block', strtolower( implode( ' ', $report['notes'] ) ) );
		} finally {
			remove_all_filters( 'pre_get_block_templates' );
		}
	}

	public function test_inline_navigation_is_established_by_fresh_published_html(): void {
		$target = self::factory()->post->create( array( 'post_title' => 'Inline target', 'post_name' => 'inline-target', 'post_status' => 'publish' ) );
		$source = self::factory()->post->create( array( 'post_title' => 'Inline source', 'post_name' => 'inline-source', 'post_status' => 'publish' ) );
		$target_url = (string) get_permalink( $target );
		remove_filter( 'pre_get_block_templates', array( $this, 'empty_block_templates' ), 10 );
		add_filter(
			'pre_get_block_templates',
			static function () use ( $target_url ): array {
				return array(
					(object) array(
						'content' => '<!-- wp:navigation {"overlayMenu":"never"} --><!-- wp:navigation-link {"label":"Inline target","url":"' . esc_url( $target_url ) . '"} /--><!-- /wp:navigation -->',
					),
				);
			},
			10,
			3
		);
		try {
			$links = ( new \SEOStudio\Architecture\NavigationSurvey() )->collect()['links'];
			$hrefs = array_column( $links, 'href' );
			$this->assertContains( $target_url, $hrefs );
			foreach ( $this->publishedIds() as $post_id ) {
				$observed = (int) $post_id === (int) $source
					? array(
						array(
							'href'   => $target_url,
							'anchor' => 'Inline target',
							'kind'   => 'template',
						),
					)
					: array();
				$this->store( $post_id, 'live', $observed );
			}
			$report = ( new SiteSurvey() )->report();
			$this->assertTrue( $report['coverage']['complete'] );
			$this->assertSame( 0, $report['coverage']['needs_published_check'] );
			$this->assertFalse( $report['coverage']['reasons']['navigation_limited'] );
			$row = $this->surveyRow( $target );
			$this->assertSame( 'template_only', $row['status'] );
			$this->assertNotSame( 'orphan', $row['status'] );
			$this->assertIsInt( $report['counts']['orphans'] );
		} finally {
			remove_all_filters( 'pre_get_block_templates' );
		}
	}

	public function test_ref_navigation_and_a_published_check_filter_share_one_count(): void {
		$ids = $this->publishedIds();
		while ( count( $ids ) < 26 ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_title'  => 'Coverage ' . count( $ids ),
				)
			);
			$ids = $this->publishedIds();
		}
		$ids = array_values( $ids );
		$this->assertCount( 26, $ids );
		$stale = (int) $ids[21];
		foreach ( $ids as $index => $post_id ) {
			if ( $index > 21 ) {
				$this->store( (int) $post_id, 'content', array() );
				continue;
			}
			$this->store( (int) $post_id, 'live', array() );
		}
		$page = ( new PagesRepository() )->findByObject( 'post', $stale );
		$this->assertIsArray( $page );
		$model = json_decode( (string) $page['page_model'], true );
		$this->assertIsArray( $model );
		unset( $model['link_source_hash'] );
		global $wpdb;
		$wpdb->update(
			\SEOStudio\Storage\Tables::pages(),
			array(
				'page_model'         => wp_json_encode( $model ),
				'onpage_state'       => 'current',
				'onpage_score'       => 40,
				'onpage_denominator' => 80,
				'onpage_rating'      => 'orange',
				'evidence_ready'     => 1,
			),
			array( 'id' => (int) $page['id'] )
		);

		$report = ( new SiteSurvey() )->report();
		$this->assertSame( 26, $report['counts']['analysed'] );
		$this->assertSame( 0, $report['coverage']['missing'] );
		$this->assertSame( 0, $report['coverage']['reasons']['unanalysed'] );
		$this->assertSame( 5, $report['coverage']['needs_published_check'] );
		$this->assertSame( 21, $report['counts']['analysed'] - $report['coverage']['needs_published_check'] );
		$this->assertNull( $report['counts']['orphans'] );

		do_action( 'rest_api_init' );
		$request = new \WP_REST_Request( 'GET', '/seostudio/v1/pages' );
		$request->set_param( 'score_state', 'needs_published_check' );
		$request->set_param( 'per_page', 50 );
		$data = rest_do_request( $request )->get_data();
		$found = array_map(
			static function ( $item ): int {
				return is_array( $item ) ? (int) ( $item['object_id'] ?? 0 ) : 0;
			},
			is_array( $data['items'] ?? null ) ? $data['items'] : array()
		);
		$this->assertSame( 5, $data['total'] );
		$this->assertContains( $stale, $found );
		$stale_item = null;
		foreach ( $data['items'] as $item ) {
			if ( is_array( $item ) && (int) $item['object_id'] === $stale ) {
				$stale_item = $item;
			}
		}
		$this->assertIsArray( $stale_item );
		$this->assertSame( 'current', $stale_item['score_state'] );
		$this->assertSame( 40, $stale_item['onpage']['score'] );
		$this->assertNotContains( (int) $ids[0], $found );
	}

	public function test_unverified_links_are_listed_only_when_a_destination_is_unknown(): void {
		$source = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Unknown source' ) );
		$target = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Known target' ) );
		foreach ( $this->publishedIds() as $post_id ) {
			$links = (int) $post_id === (int) $source
				? array(
					array(
						'href'   => home_url( '/zz-unverified-token/' ),
						'anchor' => 'Unverified',
						'kind'   => 'contextual',
					),
				)
				: array();
			$this->store( $post_id, 'live', $links );
		}
		unset( $target );

		$report = ( new SiteSurvey() )->report();
		$this->assertNotEmpty( $report['unverified'] );
		$this->assertStringContainsString( 'zz-unverified-token', (string) $report['unverified'][0]['href'] );

		foreach ( $this->publishedIds() as $post_id ) {
			$this->store( $post_id, 'content', array() );
		}
		$empty = ( new SiteSurvey() )->report();
		$this->assertSame( array(), $empty['unverified'] );
	}

	/**
	 * @param array<int, array<string, mixed>> $links
	 */
	/**
	 * @return array<string, mixed>
	 */
	private function surveyRow( int $postId ): array {
		foreach ( ( new SiteSurvey() )->evaluated()['pages'] as $page ) {
			if ( is_array( $page ) && (int) ( $page['post_id'] ?? 0 ) === $postId ) {
				return $page;
			}
		}
		$this->fail( 'Missing survey row.' );
	}

	private function store( int $postId, string $mode, array $links ): void {
		$model = new PageModel(
			url: (string) get_permalink( $postId ),
			renderMode: $mode,
			links: $links
		);
		( new PagesRepository() )->storeModel( $postId, $model, $model->inputHash() );
	}

	/**
	 * @return int[]
	 */
	private function publishedIds(): array {
		$ids = get_posts(
			array(
				'post_type'      => PostTypes::public(),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * @param array<string, mixed> $report
	 */
	private function text( array $report ): string {
		return implode( ' ', is_array( $report['notes'] ?? null ) ? $report['notes'] : array() ) . ' ' . $this->explanation( $report );
	}

	/**
	 * @param array<string, mixed> $report
	 */
	private function explanation( array $report ): string {
		return (string) ( $report['depth_explanation'] ?? '' ) . ' ' . (string) ( $report['orphan_explanation'] ?? '' );
	}
}
