<?php
/**
 * Suggestion state is decided by stored counts, not by an empty item list.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Core\Plugin;
use SEOStudio\Storage\Tables;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The pages route names unscored, clear, and items explicitly.
 */
final class SuggestionStateTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		Plugin::init();
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_suggestion_state_follows_scores_and_findings(): void {
		$empty = $this->suggestions();
		$this->assertSame( 'unscored', $empty['summary']['suggestion_state'] );
		$this->assertArrayNotHasKey( 'suggestion_state', $this->pages()['summary'] );

		$post = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Scored page',
			)
		);
		$this->store( $post, 'fail' );
		$findings = $this->suggestions();
		$this->assertSame( 'items', $findings['summary']['suggestion_state'] );
		$this->assertNotEmpty( $findings['items'] );

		$this->store( $post, 'pass' );
		$clear = $this->suggestions();
		$this->assertSame( 'clear', $clear['summary']['suggestion_state'] );
		$this->assertSame( 0, $clear['summary']['unchecked'] );
		$this->assertArrayNotHasKey( 'suggestion_notice', $clear['summary'] );
	}

	public function test_one_checked_page_does_not_clear_the_unchecked_rest(): void {
		$this->scoreMany( 1, 'pass' );
		$this->uncheckedMany( 25, 'not_analysed' );
		$report = $this->suggestions();
		$this->assertSame( 'incomplete', $report['summary']['suggestion_state'] );
		$this->assertSame( 1, $report['summary']['checked'] );
		$this->assertSame( 25, $report['summary']['unchecked'] );
		$this->assertSame(
			'No fixes found on the 1 checked page. 25 pages haven\'t been checked yet.',
			$report['summary']['suggestion_notice']
		);
		$this->assertPopulation( $report );
	}

	public function test_three_checked_pages_name_every_incomplete_kind(): void {
		$this->scoreMany( 3, 'pass' );
		$this->uncheckedMany( 6, 'needs_reanalysis' );
		$this->uncheckedMany( 6, 'unscored' );
		$this->uncheckedMany( 6, 'not_analysed' );
		$this->uncheckedMany( 5, 'current_not_ready' );
		$report = $this->suggestions();
		$this->assertSame( 'incomplete', $report['summary']['suggestion_state'] );
		$this->assertSame( 3, $report['summary']['checked'] );
		$this->assertSame( 23, $report['summary']['unchecked'] );
		$this->assertSame(
			'No fixes found on the 3 checked pages. 23 pages haven\'t been checked yet.',
			$report['summary']['suggestion_notice']
		);
		$first = $this->uncheckedList( 1, 20 );
		$second = $this->uncheckedList( 2, 20 );
		$this->assertSame( 23, $first['total'] );
		$this->assertSame( 2, $first['pages'] );
		$this->assertCount( 20, $first['items'] );
		$this->assertCount( 3, $second['items'] );
		$titles = array();
		foreach ( array_merge( $first['items'], $second['items'] ) as $item ) {
			$this->assertSame( 'check_published', $item['recovery_action'] );
			$this->assertSame( 'Check published page', $item['recovery_label'] );
			$titles[] = (string) $item['title'];
		}
		$this->assertNotEmpty( preg_grep( '/needs_reanalysis/', $titles ) );
		$this->assertNotEmpty( preg_grep( '/unscored/', $titles ) );
		$this->assertNotEmpty( preg_grep( '/not_analysed/', $titles ) );
		$this->assertNotEmpty( preg_grep( '/current_not_ready/', $titles ) );
		$this->assertPopulation( $report );
	}

	public function test_ready_pages_without_findings_are_all_clear(): void {
		$this->scoreMany( 4, 'pass' );
		$clear = $this->suggestions();
		$this->assertSame( 'clear', $clear['summary']['suggestion_state'] );
		$this->assertSame( 4, $clear['summary']['checked'] );
		$this->assertSame( 0, $clear['summary']['unchecked'] );
		$this->assertArrayNotHasKey( 'suggestion_notice', $clear['summary'] );
		$this->assertArrayNotHasKey( 'recovery_action', $clear['items'][0] );
	}

	public function test_findings_remain_distinct_from_an_incomplete_site(): void {
		$this->scoreMany( 2, 'fail' );
		$this->uncheckedMany( 3, 'not_analysed' );
		$found = $this->suggestions();
		$this->assertSame( 'items', $found['summary']['suggestion_state'] );
		$this->assertNotEmpty( $found['items'] );
		$this->assertSame( 3, $found['summary']['unchecked'] );
		$this->assertSame( '3 pages haven\'t been checked yet.', $found['summary']['suggestion_notice'] );
		$this->assertPopulation( $found );
	}

	public function test_stale_and_unready_pages_are_not_all_clear(): void {
		$this->uncheckedMany( 4, 'needs_reanalysis' );
		$stale = $this->suggestions();
		$this->assertSame( 'unscored', $stale['summary']['suggestion_state'] );
		$this->assertSame( 0, $stale['summary']['checked'] );
		$this->assertSame( 4, $stale['summary']['unchecked'] );
		$stale_list = $this->uncheckedList( 1, 20 );
		$this->assertSame( 4, $stale_list['total'] );
		$this->assertSame( 'check_published', $stale_list['items'][0]['recovery_action'] );

		$this->uncheckedMany( 2, 'current_not_ready' );
		$mixed = $this->suggestions();
		$this->assertSame( 'unscored', $mixed['summary']['suggestion_state'] );
		$this->assertSame( 6, $mixed['summary']['unchecked'] );
		$this->assertSame( 6, $this->uncheckedList( 1, 20 )['total'] );
	}

	public function test_no_scored_pages_are_not_all_clear(): void {
		$empty = $this->suggestions();
		$this->assertSame( 'unscored', $empty['summary']['suggestion_state'] );
		$this->assertSame( 0, $empty['summary']['checked'] );
		$this->assertSame( 0, $empty['summary']['unchecked'] );
		$this->assertArrayNotHasKey( 'suggestion_notice', $empty['summary'] );
	}

	public function test_counts_use_the_same_authorised_population(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$checked = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'publish',
				'post_title'  => 'Author checked',
			)
		);
		$this->store( $checked, 'pass' );
		$own = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'publish',
				'post_title'  => 'Author unchecked',
			)
		);
		$this->insertBare( $own, 'not_analysed' );
		$other = self::factory()->post->create(
			array(
				'post_author' => $editor,
				'post_status' => 'publish',
				'post_title'  => 'Editor unchecked',
			)
		);
		$this->insertBare( $other, 'unscored' );

		wp_set_current_user( $author );
		$author_report = $this->suggestions();
		$author_list   = $this->uncheckedList( 1, 20 );
		$this->assertSame( 1, $author_report['summary']['checked'] );
		$this->assertSame( 1, $author_report['summary']['unchecked'] );
		$this->assertSame( 1, $author_list['total'] );
		$this->assertSame( 'Author unchecked', $author_list['items'][0]['title'] );
		$this->assertSame( 'incomplete', $author_report['summary']['suggestion_state'] );

		wp_set_current_user( $editor );
		$editor_report = $this->suggestions();
		$editor_list   = $this->uncheckedList( 1, 20 );
		$this->assertSame( 1, $editor_report['summary']['checked'] );
		$this->assertSame( 2, $editor_report['summary']['unchecked'] );
		$this->assertSame( $editor_report['summary']['unchecked'], $editor_list['total'] );

		$blocked = static function ( array $caps, string $cap, int $user_id, array $args ) use ( $own ): array {
			unset( $user_id );
			if ( 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $own ) {
				return array( 'do_not_allow' );
			}
			return $caps;
		};
		wp_set_current_user( $author );
		add_filter( 'map_meta_cap', $blocked, 10, 4 );
		$custom      = $this->suggestions();
		$custom_list = $this->uncheckedList( 1, 20 );
		remove_filter( 'map_meta_cap', $blocked, 10 );
		$this->assertSame( 1, $custom['summary']['checked'] );
		$this->assertSame( 0, $custom['summary']['unchecked'] );
		$this->assertSame( 0, $custom_list['total'] );
		$this->assertSame( 'clear', $custom['summary']['suggestion_state'] );
	}

	/**
	 * @param array<string, mixed> $suggestions Suggestions response.
	 */
	private function assertPopulation( array $suggestions ): void {
		$plain = $this->pages();
		$this->assertSame(
			(int) $plain['summary']['analysed'],
			(int) $suggestions['summary']['checked'] + (int) $suggestions['summary']['unchecked']
		);
		$this->assertSame( (int) $suggestions['summary']['unchecked'], $this->uncheckedList( 1, 100 )['total'] );
	}

	private function scoreMany( int $count, string $status ): void {
		for ( $index = 0; $index < $count; $index++ ) {
			$post = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_title'  => 'Scored ' . $status . ' ' . $index,
				)
			);
			$this->store( $post, $status );
		}
	}

	private function uncheckedMany( int $count, string $kind ): void {
		for ( $index = 0; $index < $count; $index++ ) {
			$post = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_title'  => 'unchecked ' . $kind . ' ' . $index,
				)
			);
			$this->insertBare( $post, $kind );
		}
	}

	private function insertBare( int $postId, string $kind ): void {
		global $wpdb;

		$pages = Tables::pages();
		$url   = 'https://example.test/?p=' . $postId;
		$state = 'current_not_ready' === $kind ? 'current' : $kind;
		$wpdb->delete( $pages, array( 'object_id' => $postId ) );
		$inserted = $wpdb->insert(
			$pages,
			array(
				'object_type'        => 'post',
				'object_id'          => $postId,
				'url'                => $url,
				'url_hash'           => md5( $url ),
				'input_hash'         => md5( 'bare-' . $postId ),
				'page_model'         => '{"h1":["Stored model"]}',
				'render_mode'        => 'content',
				'content_selector'   => '',
				'last_rendered_at'   => current_time( 'mysql', true ),
				'onpage_state'       => $state,
				'evidence_ready'     => 0,
				'onpage_score'       => 'current' === $state ? 10 : null,
				'onpage_denominator' => 'current' === $state ? 100 : null,
				'onpage_rating'      => 'current' === $state ? 'orange' : null,
				'index_status'       => 'unobserved',
			)
		);
		$this->assertNotFalse( $inserted, (string) $wpdb->last_error );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function uncheckedList( int $page, int $perPage ): array {
		$request = new WP_REST_Request( 'GET', '/seostudio/v1/pages' );
		$request->set_query_params(
			array(
				'score_state' => 'unchecked',
				'page'        => (string) $page,
				'per_page'    => (string) $perPage,
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		return $data;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function suggestions(): array {
		$request = new WP_REST_Request( 'GET', '/seostudio/v1/pages' );
		// Same query string the dashboard sends. Boolean and integer args must survive sanitising.
		$request->set_query_params(
			array(
				'suggestions' => '1',
				'score_min'   => '0',
				'per_page'    => '3',
				'orderby'     => 'score',
				'order'       => 'asc',
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		return $data;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function pages(): array {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/pages' ) );
		$data     = $response->get_data();
		$this->assertIsArray( $data );
		return $data;
	}

	private function store( int $postId, string $status ): void {
		global $wpdb;

		$pages  = Tables::pages();
		$scores = Tables::scores();
		$url    = 'https://example.test/?p=' . $postId;
		$now    = current_time( 'mysql', true );
		$wpdb->delete( $pages, array( 'object_id' => $postId ) );
		$wpdb->insert(
			$pages,
			array(
				'object_type'        => 'post',
				'object_id'          => $postId,
				'url'                => $url,
				'url_hash'           => md5( $url ),
				'input_hash'         => md5( 'input-' . $postId ),
				'page_model'         => '{}',
				'render_mode'        => 'live',
				'content_selector'   => '',
				'last_rendered_at'   => $now,
				'onpage_state'       => 'current',
				'evidence_ready'     => 1,
				'onpage_score'       => 90,
				'onpage_denominator' => 100,
				'onpage_rating'      => 'green',
				'index_status'       => 'indexable',
			)
		);
		$page_id = (int) $wpdb->insert_id;
		$details = wp_json_encode(
			array(
				'evidence_version' => 2,
				'assessments'      => array(
					array(
						'id'     => 'title.length',
						'status' => $status,
					),
				),
			)
		);
		$wpdb->insert(
			$scores,
			array(
				'page_id'         => $page_id,
				'keyphrase'       => 'rank tracker',
				'keyphrase_hash'  => md5( 'rank tracker' ),
				'engine'          => 'onpage',
				'score'           => 90,
				'denominator'     => 100,
				'rating'          => 'green',
				'details'         => $details,
				'plugin_version'  => 'test',
				'created_at'      => $now,
			)
		);
		$wpdb->update(
			$pages,
			array(
				'onpage_score_id' => (int) $wpdb->insert_id,
				'focus_hash'      => md5( 'rank tracker' ),
			),
			array( 'id' => $page_id )
		);
	}
}
