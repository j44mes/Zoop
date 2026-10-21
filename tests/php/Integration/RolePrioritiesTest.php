<?php
/**
 * Priorities stay available without exposing other people's pages.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Content\PageModel;
use SEOStudio\Core\Plugin;
use SEOStudio\Recommendations\Priorities;
use SEOStudio\Recommendations\PrioritiesReport;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Storage\Tables;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Editors and authors receive findings they can act on.
 */
final class RolePrioritiesTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		Plugin::init();
		do_action( 'rest_api_init' );
	}

	public function test_roles_see_only_actionable_findings_including_past_the_display_limit(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$other  = self::factory()->user->create( array( 'role' => 'author' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$secret = $this->ownedPost( $other, 'Secret other title' );
		$this->markNoindex( $secret, true );
		$private = self::factory()->post->create(
			array(
				'post_author' => $other,
				'post_status' => 'private',
				'post_title'  => 'Private secret page',
			)
		);
		$this->markNoindex( $private, true );
		for ( $index = 1; $index <= 40; $index++ ) {
			$this->markNoindex( $this->ownedPost( $other, 'Filler page ' . $index ), true );
		}
		$mine = $this->ownedPost( $author, 'Author visible page' );
		$this->markNoindex( $mine, true );
		$stale = $this->ownedPost( $author, 'Stale noindex page' );
		$this->markNoindex( $stale, false );

		wp_set_current_user( $author );
		$author_report = ( new PrioritiesReport() )->report();
		$author_item   = $this->visibilityItem( $author_report );
		$this->assertSame( '1 page is hidden from search.', $author_item['title'] );
		$this->assertSame( array(), $author_item['pages'] );
		$this->assertStringContainsString( 'visibility=noindex', (string) $author_item['href'] );
		$author_json = (string) wp_json_encode( $author_report );
		$this->assertStringNotContainsString( 'Secret other title', $author_json );
		$this->assertStringNotContainsString( 'Private secret page', $author_json );
		$this->assertStringNotContainsString( 'Stale noindex page', $author_json );
		$this->assertStringNotContainsString( 'Filler page 1', $author_json );
		$this->assertStringNotContainsString( 'Import a Search Console file', $author_json );
		$this->assertLessThanOrEqual( 40, count( $author_report['items'] ) );

		wp_set_current_user( $editor );
		$editor_report = ( new PrioritiesReport() )->report();
		$editor_item   = $this->visibilityItem( $editor_report );
		$this->assertSame( '43 pages are hidden from search.', $editor_item['title'] );
		$this->assertSame( array(), $editor_item['pages'] );
		$this->assertLessThanOrEqual( 40, count( $editor_report['items'] ) );

		wp_set_current_user( $admin );
		$admin_report = ( new PrioritiesReport() )->report();
		$admin_item   = $this->visibilityItem( $admin_report );
		$this->assertSame( '43 pages are hidden from search.', $admin_item['title'] );
		$this->assertCount( 1, array_filter( $admin_report['items'], static fn ( array $item ): bool => 'visibility' === ( $item['kind'] ?? '' ) ) );
		$this->assertLessThanOrEqual( 40, count( $admin_report['items'] ) );

		wp_set_current_user( $author );
		$hidden_secret = $this->pages( 'noindex', 'Secret other title' );
		$this->assertSame( 0, $hidden_secret['total'] );
		$stale_hidden = $this->pages( 'noindex', 'Stale noindex page' );
		$this->assertSame( 0, $stale_hidden['total'] );
		$stale_list = $this->pages( '', 'Stale noindex page', 'needs_reanalysis' );
		$this->assertSame( 1, $stale_list['total'] );
		wp_set_current_user( $editor );
		$editor_secret = $this->pages( 'noindex', 'Secret other title' );
		$this->assertSame( 1, $editor_secret['total'] );
		$this->assertSame( 'Secret other title', $editor_secret['items'][0]['title'] );

		$ranked = $this->encoded( $this->mixedGroup( $author, $mine, $secret ) );
		$this->assertStringContainsString( 'shared phrase', $ranked );
		$this->assertStringContainsString( 'Author visible page', $ranked );
		$this->assertStringNotContainsString( 'Secret other title', $ranked );
		$this->assertStringNotContainsString( 'https://example.test/secret/', $ranked );
		$this->assertStringNotContainsString( 'https://example.test/missing/', $ranked );

		wp_set_current_user( $editor );
		$editor_group = $this->encoded( $this->mixedGroup( $editor, $mine, $secret ) );
		$this->assertStringContainsString( 'Secret other title', $editor_group );
		$this->assertStringContainsString( 'https://example.test/missing/', $editor_group );

		$blocked = static function ( array $caps, string $cap, int $user_id, array $args ) use ( $secret ): array {
			unset( $user_id );
			if ( 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $secret ) {
				return array( 'do_not_allow' );
			}
			return $caps;
		};
		add_filter( 'map_meta_cap', $blocked, 10, 4 );
		$custom = $this->encoded( $this->mixedGroup( $editor, $mine, $secret ) );
		remove_filter( 'map_meta_cap', $blocked, 10 );
		$this->assertStringNotContainsString( 'Secret other title', $custom );
		$this->assertStringContainsString( 'Author visible page', $custom );

		wp_set_current_user( $author );
		$allowed = rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/recommendations' ) );
		$this->assertSame( 200, $allowed->get_status() );
		$this->assertStringNotContainsString( 'Secret other title', (string) wp_json_encode( $allowed->get_data() ) );
		foreach ( array( '/seostudio/v1/settings', '/seostudio/v1/architecture', '/seostudio/v1/overlap', '/seostudio/v1/search-console' ) as $route ) {
			$hidden = rest_do_request( new WP_REST_Request( 'GET', $route ) );
			$this->assertSame( 403, $hidden->get_status(), $route );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$denied = rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/recommendations' ) );
		$this->assertSame( 403, $denied->get_status() );
	}

	/**
	 * @param array<string, mixed> $report Ranked report.
	 * @return array<string, mixed>
	 */
	private function visibilityItem( array $report ): array {
		foreach ( $report['items'] as $item ) {
			if ( 'visibility' === ( $item['kind'] ?? '' ) ) {
				return $item;
			}
		}
		$this->fail( 'Missing grouped noindex finding.' );
		return array();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function pages( string $visibility, string $search, string $score_state = '' ): array {
		$request = new WP_REST_Request( 'GET', '/seostudio/v1/pages' );
		$request->set_param( 'search', $search );
		if ( '' !== $visibility ) {
			$request->set_param( 'visibility', $visibility );
		}
		if ( '' !== $score_state ) {
			$request->set_param( 'score_state', $score_state );
		}
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		return $data;
	}

	/**
	 * @param array<string, mixed> $report Ranked report.
	 */
	private function encoded( array $report ): string {
		return str_replace( '\\/', '/', (string) wp_json_encode( $report ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function mixedGroup( int $user, int $mine, int $secret ): array {
		wp_set_current_user( $user );
		return ( new Priorities() )->rank(
			array(
				'coverage'   => array( 'complete' => true ),
				'orphans'    => array(),
				'weak'       => array(),
				'unresolved' => array(
					array(
						'from_id'     => 'post:' . $secret,
						'from_title'  => 'Secret other title',
						'href'        => 'https://example.test/missing/',
						'occurrences' => 2,
					),
				),
			),
			array(
				'groups' => array(
					array(
						'observation' => 'phrase',
						'phrase'      => 'shared phrase',
						'reason'      => 'These pages use the same target phrase.',
						'pages'       => array(
							array(
								'post_id' => $mine,
								'title'   => 'Author visible page',
								'url'     => 'https://example.test/mine/',
							),
							array(
								'post_id' => $secret,
								'title'   => 'Secret other title',
								'url'     => 'https://example.test/secret/',
							),
						),
					),
				),
			),
			array(
				array(
					'query'       => 'rank tracker',
					'page'        => 'https://example.test/mine/',
					'clicks'      => 1,
					'impressions' => 4,
				),
			)
		);
	}

	private function ownedPost( int $author, string $title ): int {
		return self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
	}

	private function markNoindex( int $postId, bool $fresh ): void {
		$page_id = ( new PagesRepository() )->storeModel(
			$postId,
			new PageModel(
				url: 'https://example.test/?p=' . $postId,
				titleTag: 'Rank tracker guide',
				titleEvidence: 'observed',
				documentH1: array( 'Rank tracker guide' ),
				documentH1Evidence: 'observed',
				renderMode: 'live',
				metadataStatus: 'observed_html'
			),
			'hash-' . $postId
		);
		global $wpdb;
		$wpdb->update(
			Tables::pages(),
			array(
				'evidence_ready'     => $fresh ? 1 : 0,
				'onpage_state'       => $fresh ? 'current' : 'needs_reanalysis',
				'index_status'       => 'noindex',
				'index_source'       => 'meta',
				'onpage_score'       => 90,
				'onpage_denominator' => 100,
				'onpage_rating'      => 'green',
			),
			array( 'id' => $page_id )
		);
	}
}
