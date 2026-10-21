<?php
/**
 * Content checks stay distinct from a definitive on-page score.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Content\FetchPolicy;
use SEOStudio\Content\LoopbackFetcher;
use SEOStudio\Content\PageModel;
use SEOStudio\Core\Plugin;
use SEOStudio\Rest\PagesController;
use SEOStudio\Storage\PagesRepository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Gutenberg and the classic meta box both read this record.
 */
final class ContentCheckedPresentationTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		Plugin::init();
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$home = static function (): string {
			return 'https://example.test';
		};
		add_filter( 'pre_option_home', $home );
		add_filter( 'pre_option_siteurl', $home );
	}

	public function test_a_content_check_asks_for_a_published_page_before_it_scores(): void {
		$post = $this->post( 'Rank tracker title' );
		$data = $this->analyse( $post, 'content' );

		$this->assertFalse( $data['presentation']['definitive'] );
		$this->assertSame( 'content', $data['render_mode'] );
		$this->assertSame( 'Content checked. Needs a published-page check to score.', $data['presentation']['headline'] );
		$this->assertSame( '', $data['presentation']['content_rating'] );
		$this->assertNotSame( '', $data['presentation']['missing'][0]['action_label'] );
		$actions = array_column( $data['presentation']['missing'], 'action' );
		$this->assertContains( 'check_published', $actions );
		$this->assertContains( 'Check published page', array_column( $data['presentation']['missing'], 'action_label' ) );

		$list = $this->listItem( $post );
		$this->assertSame( 'Content checked. Needs a published-page check to score.', $list['presentation']['headline'] );
		$this->assertSame( '', $list['presentation']['content_rating'] );
		$this->assertNull( $list['onpage']['score'] );
		$this->assertSame( 'check_published', $list['recovery_action'] );
		$this->assertSame( 'Check published page', $list['recovery_label'] );
	}

	public function test_a_content_check_is_labelled_before_the_focus_is_saved(): void {
		$post = self::factory()->post->create(
			array(
				'post_status'  => 'draft',
				'post_title'   => 'Draft note',
				'post_content' => '<p>Body copy for the page.</p>',
			)
		);
		$data = $this->analyse( $post, 'content' );

		$this->assertFalse( $data['presentation']['definitive'] );
		$this->assertSame( 'Content checked. Needs a published-page check to score.', $data['presentation']['headline'] );
		$this->assertContains( 'Check published page', array_column( $data['presentation']['missing'], 'action_label' ) );
	}

	public function test_out_of_date_and_not_analysed_headlines_stay_distinct(): void {
		$post = $this->post( 'Rank tracker title' );
		$this->analyse( $post, 'live' );
		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => 'Changed title',
			)
		);
		$stale = $this->record( $post );
		$this->assertSame( 'Out of date', $stale['presentation']['headline'] );
		$this->assertSame( '', $stale['presentation']['content_rating'] );
		$this->assertFalse( $stale['presentation']['definitive'] );

		$plain   = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Stored only',
			)
		);
		$page_id = ( new PagesRepository() )->save(
			array(
				'object_type' => 'post',
				'object_id'   => $plain,
				'url'         => 'https://example.test/?p=' . $plain,
				'render_mode' => 'content',
				'page_model'  => array( 'url' => 'https://example.test/?p=' . $plain ),
			)
		);
		$open    = $this->show( $page_id );
		$this->assertSame( 'Not enough evidence yet', $open['presentation']['headline'] );
		$this->assertSame( '', $open['presentation']['content_rating'] );
	}

	public function test_a_published_check_keeps_its_score_and_rating(): void {
		$post = $this->post( 'Rank tracker title' );
		$data = $this->analyse( $post, 'live' );

		$this->assertTrue( $data['presentation']['definitive'] );
		$this->assertStringContainsString( ' / ', $data['presentation']['headline'] );
		$this->assertNotSame( 'Content checked. Needs a published-page check to score.', $data['presentation']['headline'] );
		$this->assertNotSame( '', $data['presentation']['content_rating'] );
		$this->assertGreaterThan( 0, $data['scores'][0]['denominator'] );
	}

	public function test_the_classic_editor_query_selects_the_classic_panel(): void {
		$post = get_post( $this->post( 'Classic page' ) );
		$this->assertInstanceOf( \WP_Post::class, $post );
		unset( $_GET['seostudio_editor'] );
		$block = use_block_editor_for_post( $post );

		$_GET['seostudio_editor'] = 'classic';
		$this->assertFalse( use_block_editor_for_post( $post ) );
		$this->assertFalse( use_block_editor_for_post_type( 'post' ) );

		unset( $_GET['seostudio_editor'] );
		$this->assertSame( $block, use_block_editor_for_post( $post ) );
	}

	private function post( string $title ): int {
		$post = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => '<h1>Inside the article</h1><p>Body copy for the page.</p>',
			)
		);
		update_post_meta( $post, '_seostudio_focus_keyphrase', 'rank tracker' );

		return $post;
	}

	/**
	 * Analyse one post in content or live mode.
	 *
	 * @return array<string, mixed>
	 */
	private function analyse( int $postId, string $mode ): array {
		if ( 'live' === $mode ) {
			$fetcher = new LoopbackFetcher(
				policy: new FetchPolicy( static fn (): array => array( '93.184.216.34' ) ),
				transport: static fn (): array => array(
					'code'     => 200,
					'location' => '',
					'body'     => '<html><head><title>Rank tracker title</title></head><body><header><h1>Rank tracker title</h1></header><main><p>Body copy for the page.</p></main></body></html>',
					'robots'   => '',
				)
			);
			add_filter(
				'post_link',
				static function () use ( $postId ): string {
					return 'https://example.test/?p=' . $postId;
				}
			);
			$request = new WP_REST_Request( 'POST' );
			$request->set_param( 'object_type', 'post' );
			$request->set_param( 'object_id', $postId );
			$request->set_param( 'mode', 'live' );
			$request->set_param( 'keyphrase', 'rank tracker' );
			$result = ( new PagesController( fetcher: $fetcher ) )->analyseObject( $request );
			$this->assertNotInstanceOf( \WP_Error::class, $result );

			return $result->get_data();
		}

		$request = new WP_REST_Request( 'POST', '/seostudio/v1/analyse/post/' . $postId );
		$request->set_param( 'mode', 'content' );
		$request->set_param( 'keyphrase', 'rank tracker' );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		return $response->get_data();
	}

	/**
	 * Full record for the page stored against a post.
	 *
	 * @return array<string, mixed>
	 */
	private function record( int $postId ): array {
		$row = ( new PagesRepository() )->findByObject( 'post', $postId );
		$this->assertIsArray( $row );

		return $this->show( (int) $row['id'] );
	}

	/**
	 * Full record for one page id.
	 *
	 * @return array<string, mixed>
	 */
	private function show( int $pageId ): array {
		$request  = new WP_REST_Request( 'GET', '/seostudio/v1/pages/' . $pageId );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		return $response->get_data();
	}

	/**
	 * List row for one post.
	 *
	 * @return array<string, mixed>
	 */
	private function listItem( int $postId ): array {
		$request = new WP_REST_Request( 'GET', '/seostudio/v1/pages' );
		$request->set_param( 'object_id', $postId );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data()['items'] );

		return $response->get_data()['items'][0];
	}
}
