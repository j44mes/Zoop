<?php
/**
 * Every Zoop REST route refuses callers without the right capability.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Security;

use SEOStudio\Content\PageModel;
use SEOStudio\Core\Plugin;
use SEOStudio\Storage\PagesRepository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Routes are discovered from the server, so a new route is covered without editing this test.
 */
final class RouteAccessTest extends WP_UnitTestCase {
	/**
	 * Routes a contributor may call for content they do not own. They filter or validate per post.
	 */
	private const CONTRIBUTOR_ALLOWED = array(
		'GET /seostudio/v1/pages',
		'GET /seostudio/v1/keyphrases/usage',
	);

	private int $postId = 0;

	private int $pageId = 0;

	private int $checked = 0;

	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		do_action( 'init' );
		do_action( 'rest_api_init' );

		$author       = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->postId = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'publish',
			)
		);
		$this->pageId = ( new PagesRepository() )->storeModel( $this->postId, new PageModel( url: 'https://example.test/owned' ), '' );
	}

	public function test_anonymous_callers_get_401_on_every_route(): void {
		wp_set_current_user( 0 );
		$this->assertSame( array(), $this->unexpected( 401, array() ) );
	}

	public function test_subscribers_get_403_on_every_route(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( array(), $this->unexpected( 403, array() ) );
	}

	public function test_contributors_cannot_touch_another_authors_post_or_admin_routes(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$this->assertSame( array(), $this->unexpected( 403, self::CONTRIBUTOR_ALLOWED ) );
	}

	public function test_contributors_do_not_see_another_authors_draft_in_the_list(): void {
		$author = (int) get_post_field( 'post_author', $this->postId );
		$draft  = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'draft',
				'post_title'  => 'Private draft title',
			)
		);
		( new PagesRepository() )->storeModel( $draft, new PageModel( url: 'https://example.test/draft' ), '' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$list = rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/pages' ) );
		$this->assertSame( 200, $list->get_status() );
		$this->assertStringNotContainsString( 'Private draft title', (string) wp_json_encode( $list->get_data() ) );
	}

	/**
	 * Call every route and method; list each response that is not the expected refusal.
	 *
	 * @param string[] $allowed "METHOD /route" pairs exempt from the expectation.
	 * @return string[]
	 */
	private function unexpected( int $status, array $allowed ): array {
		$failures = array();
		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			if ( ! str_starts_with( $route, '/seostudio/v1/' ) ) {
				continue;
			}
			foreach ( $handlers as $handler ) {
				foreach ( array_keys( $handler['methods'] ) as $method ) {
					$path = $this->path( $route );
					if ( in_array( $method . ' ' . preg_replace( '/\/\d+/', '', $path ), $allowed, true ) ) {
						continue;
					}
					++$this->checked;
					$response = rest_do_request( $this->request( (string) $method, $path ) );
					$data     = $response->get_data();
					$code     = is_array( $data ) ? (string) ( $data['code'] ?? '' ) : '';
					if ( $status !== $response->get_status() || 'seostudio_forbidden' !== $code ) {
						$failures[] = sprintf( '%s %s returned %d %s', $method, $path, $response->get_status(), $code );
					}
				}
			}
		}

		// Sixteen routes with twenty methods exist today; a broken discovery must not pass silently.
		$this->assertGreaterThanOrEqual( 18, $this->checked + count( $allowed ) );
		return $failures;
	}

	/**
	 * Fill route placeholders with the fixture post and page.
	 */
	private function path( string $route ): string {
		return (string) preg_replace_callback(
			'/\(\?P<(\w+)>[^)]+\)/',
			function ( array $match ): string {
				return match ( $match[1] ) {
					'object_type' => 'post',
					'page_id' => (string) $this->pageId,
					'run_id' => '1',
					default => (string) $this->postId,
				};
			},
			$route
		);
	}

	/**
	 * A request with parameters valid enough to reach the permission check.
	 */
	private function request( string $method, string $path ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $path );
		if ( 'GET' === $method ) {
			$request->set_query_params( array( 'keyphrase' => 'probe' ) );
			return $request;
		}
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '{"source":"yoast","keyphrase":"probe"}' );
		return $request;
	}
}
