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
