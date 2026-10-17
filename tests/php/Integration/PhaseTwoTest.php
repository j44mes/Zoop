<?php
/**
 * Dashboard routes added in Phase 2.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Core\Plugin;
use SEOStudio\Storage\PagesRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Unsaved models, content-analysis storage, and the audit stub.
 */
final class PhaseTwoTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		do_action( 'init' );
		do_action( 'rest_api_init' );
	}

	public function test_model_preview_does_not_create_a_page_row(): void {
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_author'  => $editor,
				'post_title'   => 'Welcome',
				'post_content' => '<p>Welcome to the page.</p>',
			)
		);
		wp_set_current_user( $editor );

		$request = new WP_REST_Request( 'POST', '/seostudio/v1/posts/' . $post_id . '/model' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'content_html' => '<p>The rank tracker guide starts with the rank tracker.</p>',
					'title'        => 'rank tracker guide',
					'excerpt'      => 'A rank tracker guide.',
					'keyphrase'    => 'rank tracker',
				)
			)
		);
		$response = $this->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'rank tracker guide', $data['title_tag'] );
		$this->assertArrayHasKey( 'onpage', $data );
		$this->assertNull( ( new PagesRepository() )->findByObject( 'post', $post_id ) );
	}

	public function test_model_preview_scores_only_metadata_the_page_prints(): void {
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id = self::factory()->post->create( array( 'post_author' => $editor ) );
		wp_set_current_user( $editor );
		$body = array(
			'content_html'    => '<p>Body copy.</p>',
			'title'           => 'Unsaved post title',
			'excerpt'         => 'Unsaved excerpt.',
			'seo_title'       => '%%title%% guide',
			'seo_description' => 'Unsaved SEO description.',
			'keyphrase'       => 'guide',
		);

		// Analysis-only mode with no other SEO plugin prints the post title and excerpt.
		$data = $this->preview( $post_id, $body );
		$this->assertSame( 'Unsaved post title', $data['title_tag'] );
		$this->assertSame( 'Unsaved excerpt.', $data['meta_description'] );
		$this->assertSame( 'estimated', $data['metadata_status'] );

		// Owner mode prints SEO Studio's fields, with template variables expanded from the unsaved title.
		$settings         = get_option( 'seostudio_settings', array() );
		$settings['mode'] = 'owner';
		update_option( 'seostudio_settings', $settings );
		$data = $this->preview( $post_id, $body );
		$this->assertSame( 'Unsaved post title guide', $data['title_tag'] );
		$this->assertSame( 'Unsaved SEO description.', $data['meta_description'] );
	}

	public function test_contributors_cannot_preview_or_score_another_authors_post(): void {
		$author      = self::factory()->user->create( array( 'role' => 'author' ) );
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$post_id     = self::factory()->post->create( array( 'post_author' => $author ) );
		$page_id     = ( new PagesRepository() )->storeModel( $post_id, new \SEOStudio\Content\PageModel( url: 'https://example.test/other' ), '' );
		wp_set_current_user( $contributor );

		$preview = new WP_REST_Request( 'POST', '/seostudio/v1/posts/' . $post_id . '/model' );
		$preview->set_param( 'content_html', '<p>x</p>' );
		$this->assertSame( 403, $this->dispatch( $preview )->get_status() );

		$store = new WP_REST_Request( 'POST', '/seostudio/v1/pages/' . $page_id . '/content-scores' );
		$this->assertSame( 403, $this->dispatch( $store )->get_status() );
	}

	public function test_content_scores_appear_on_the_dashboard_list(): void {
		$editor  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_author'  => $editor,
				'post_content' => '<p>rank tracker belongs in the body.</p>',
				'post_title'   => 'Rank tracker',
			)
		);
		wp_set_current_user( $editor );
		update_post_meta( $post_id, '_seostudio_focus_keyphrase', 'rank tracker' );

		$analyse = new WP_REST_Request( 'POST', '/seostudio/v1/analyse/post/' . $post_id );
		$analyse->set_header( 'Content-Type', 'application/json' );
		$analyse->set_body( (string) wp_json_encode( array( 'keyphrase' => 'rank tracker' ) ) );
		$scored = $this->dispatch( $analyse );
		$this->assertSame( 200, $scored->get_status() );
		$page_id = (int) $scored->get_data()['page_id'];

		$store = new WP_REST_Request( 'POST', '/seostudio/v1/pages/' . $page_id . '/content-scores' );
		$store->set_header( 'Content-Type', 'application/json' );
		$store->set_body(
			(string) wp_json_encode(
				array(
					'keyphrase'   => 'rank tracker',
					'seo'         => array(
						'score'       => 80,
						'rating'      => 'green',
						'assessments' => array(
							array(
								'id'     => 'introductionKeyword',
								'score'  => 9,
								'rating' => 'green',
								'text'   => 'Keyphrase in introduction.',
							),
						),
					),
					'readability' => array(
						'score'       => 60,
						'rating'      => 'orange',
						'assessments' => array(),
					),
				)
			)
		);
		$this->assertSame( 200, $this->dispatch( $store )->get_status() );

		// A later result for another phrase must not replace the focus phrase's lights.
		$other = new WP_REST_Request( 'POST', '/seostudio/v1/pages/' . $page_id . '/content-scores' );
		$other->set_header( 'Content-Type', 'application/json' );
		$other->set_body(
			(string) wp_json_encode(
				array(
					'keyphrase'   => 'unrelated phrase',
					'seo'         => array(
						'score'       => 10,
						'rating'      => 'red',
						'assessments' => array(),
					),
					'readability' => array(
						'score'       => 10,
						'rating'      => 'red',
						'assessments' => array(),
					),
				)
			)
		);
		$this->assertSame( 200, $this->dispatch( $other )->get_status() );

		$list = $this->dispatch( new WP_REST_Request( 'GET', '/seostudio/v1/pages' ) );
		$this->assertSame( 200, $list->get_status() );
		$match = null;
		foreach ( $list->get_data()['items'] as $item ) {
			if ( (int) $item['page_id'] === $page_id ) {
				$match = $item;
			}
		}
		$this->assertNotNull( $match );
		$this->assertSame( 'green', $match['seo_rating'] );
		$this->assertSame( 'orange', $match['readability_rating'] );
		$summary = $list->get_data()['summary'];
		$this->assertSame( $list->get_data()['total'], $summary['analysed'] );
		$this->assertNotNull( $summary['average'] );

		update_post_meta( $post_id, '_seostudio_focus_keyphrase', 'unanalysed phrase' );
		$list = $this->dispatch( new WP_REST_Request( 'GET', '/seostudio/v1/pages' ) );
		foreach ( $list->get_data()['items'] as $item ) {
			if ( (int) $item['page_id'] === $page_id ) {
				$this->assertNull( $item['seo_rating'] );
				$this->assertNull( $item['readability_rating'] );
			}
		}
	}

	public function test_audit_route_is_not_available_yet(): void {
		$admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->dispatch( new WP_REST_Request( 'POST', '/seostudio/v1/audits' ) )->get_status() );

		wp_set_current_user( $subscriber );
		$this->assertSame( 403, $this->dispatch( new WP_REST_Request( 'POST', '/seostudio/v1/audits' ) )->get_status() );

		wp_set_current_user( $admin );
		$response = $this->dispatch( new WP_REST_Request( 'POST', '/seostudio/v1/audits' ) );
		$this->assertSame( 501, $response->get_status() );
		$this->assertSame( 'seostudio_not_available', $response->get_data()['code'] );
	}

	/**
	 * Call the unsaved-model route and return its data.
	 *
	 * @param array<string, string> $body
	 * @return array<string, mixed>
	 */
	private function preview( int $postId, array $body ): array {
		$request = new WP_REST_Request( 'POST', '/seostudio/v1/posts/' . $postId . '/model' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		$response = $this->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data();
	}

	private function dispatch( WP_REST_Request $request ): WP_REST_Response {
		$result = rest_do_request( $request );
		if ( $result instanceof WP_Error ) {
			return rest_convert_error_to_response( $result );
		}

		return $result;
	}
}
