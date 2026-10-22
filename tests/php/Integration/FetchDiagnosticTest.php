<?php
/**
 * Failed published-page checks keep the last good snapshot.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Content\FetchPolicy;
use SEOStudio\Content\LoopbackFetcher;
use SEOStudio\Content\PageModel;
use SEOStudio\Core\Plugin;
use SEOStudio\Core\Settings;
use SEOStudio\Core\Upgrader;
use SEOStudio\Jobs\FetchLive;
use SEOStudio\Rest\PagesController;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Storage\Tables;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Manual and scheduled checks store the same diagnostic and do not invent a score.
 */
final class FetchDiagnosticTest extends WP_UnitTestCase {
	private int $postId = 0;

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
		$this->postId = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Kept title',
				'post_content' => '<p>Kept body.</p>',
			)
		);
		update_post_meta( $this->postId, '_seostudio_focus_keyphrase', 'rank tracker' );
	}

	public function test_a_failed_attempt_keeps_the_previous_snapshot_and_a_later_success_clears_it(): void {
		$pages   = new PagesRepository();
		$model   = new PageModel(
			url: 'https://example.test/?p=' . $this->postId,
			titleTag: 'Kept title',
			bodyText: 'Kept body.',
			renderMode: 'live'
		);
		$page_id = $pages->storeModel( $this->postId, $model, $model->inputHash() );
		global $wpdb;
		$wpdb->insert(
			Tables::scores(),
			array(
				'page_id'        => $page_id,
				'keyphrase'      => 'rank tracker',
				'keyphrase_hash' => md5( 'rank tracker' ),
				'engine'         => 'onpage',
				'score'          => 40,
				'denominator'    => 80,
				'rating'         => 'orange',
				'details'        => wp_json_encode( array( 'earned' => 40 ) ),
				'plugin_version' => '0.5.0',
				'created_at'     => current_time( 'mysql', true ),
			)
		);
		$score_id = (int) $wpdb->insert_id;
		$pages->markFetched( $page_id );
		$before   = $pages->find( $page_id );
		$fetched  = (string) $before['last_fetched_at'];
		$snapshot = (string) $before['page_model'];

		add_filter( 'post_link', array( $this, 'permalink' ) );
		try {
			$manual = $this->controller( 202, (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/siteground-protection.html' ) )->analyseObject( $this->request() );
			$this->assertSame( 'seostudio_fetch_blocked', $manual->get_error_code() );
			$this->assertSame( 'protection_page', $manual->get_error_data()['reason'] );
			$this->assertSame( 202, $manual->get_error_data()['http_status'] );
			$this->assertStringNotContainsString( 'sgcaptcha', $manual->get_error_message() );

			$failed = $pages->find( $page_id );
			$this->assertSame( $snapshot, (string) $failed['page_model'] );
			$this->assertSame( $fetched, (string) $failed['last_fetched_at'] );
			$this->assertSame( 'protection_page', $failed['fetch_failure_reason'] );
			$this->assertSame( '202', (string) $failed['fetch_http_status'] );
			$this->assertStringContainsString( 'previous result has been kept', (string) $failed['last_error'] );
			$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::scores() . ' WHERE id = %d', $score_id ) ) );

			( new FetchLive( $this->fetcher( 403, '<html><body>Forbidden</body></html>' ) ) )->handle( array( $this->postId ) );
			$refused = $pages->find( $page_id );
			$this->assertSame( 'http_status', $refused['fetch_failure_reason'] );
			$this->assertSame( '403', (string) $refused['fetch_http_status'] );
			$this->assertStringContainsString( 'HTTP 403', (string) $refused['last_error'] );
			$this->assertStringNotContainsString( 'protection page', (string) $refused['last_error'] );
			$this->assertSame( $snapshot, (string) $refused['page_model'] );

			( new FetchLive( $this->fetcher( 200, '<html><head><title>Kept title</title></head><body><main><p>Checked body.</p></main></body></html>' ) ) )->handle( array( $this->postId ) );
			$cleared = $pages->find( $page_id );
			$this->assertNull( $cleared['last_error'] );
			$this->assertSame( '', (string) $cleared['fetch_failure_reason'] );
			$this->assertStringContainsString( 'Checked body.', (string) $cleared['page_model'] );
			$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::scores() . ' WHERE id = %d', $score_id ) ) );
		} finally {
			remove_filter( 'post_link', array( $this, 'permalink' ) );
		}
	}

	public function test_schema_three_scores_survive_the_diagnostic_migration(): void {
		$pages   = new PagesRepository();
		$model   = new PageModel( url: 'https://example.test/?p=' . $this->postId, titleTag: 'Old', renderMode: 'content' );
		$page_id = $pages->storeModel( $this->postId, $model, 'old-hash' );
		global $wpdb;
		$wpdb->insert(
			Tables::scores(),
			array(
				'page_id'        => $page_id,
				'keyphrase'      => 'rank tracker',
				'keyphrase_hash' => md5( 'rank tracker' ),
				'engine'         => 'onpage',
				'score'          => 10,
				'denominator'    => 80,
				'rating'         => 'red',
				'details'        => '{}',
				'plugin_version' => '0.5.0',
				'created_at'     => current_time( 'mysql', true ),
			)
		);
		$settings                    = Settings::all();
		$settings['schema_version']  = 3;
		update_option( Settings::OPTION, $settings, true );

		Upgrader::maybeUpgrade();

		$this->assertSame( Upgrader::CURRENT, (int) get_option( Settings::OPTION )['schema_version'] );
		$column = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . Tables::pages() . ' LIKE %s', 'fetch_failure_reason' ) );
		$this->assertSame( 'fetch_failure_reason', $column );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Tables::scores() . ' WHERE page_id = %d AND plugin_version = %s', $page_id, '0.5.0' ) ) );
	}

	public function test_an_author_cannot_read_another_authors_fetch_failure(): void {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $owner );
		$post = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $owner,
			)
		);
		update_post_meta( $post, '_seostudio_focus_keyphrase', 'rank tracker' );
		add_filter(
			'post_link',
			static function () use ( $post ): string {
				return 'https://example.test/?p=' . $post;
			}
		);
		$controller = new PagesController( fetcher: $this->fetcher( 403, 'no' ) );
		$request    = new WP_REST_Request( 'POST' );
		$request->set_param( 'object_type', 'post' );
		$request->set_param( 'object_id', $post );
		$request->set_param( 'mode', 'live' );
		$request->set_param( 'keyphrase', 'rank tracker' );
		$this->assertInstanceOf( \WP_Error::class, $controller->analyseObject( $request ) );
		$page = ( new PagesRepository() )->findByObject( 'post', $post );

		wp_set_current_user( $other );
		$this->assertNull( ( new \SEOStudio\Rest\PageRecord() )->full( (int) $page['id'] ) );
	}

	public function permalink(): string {
		return 'https://example.test/?p=' . $this->postId;
	}

	private function request(): WP_REST_Request {
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'object_type', 'post' );
		$request->set_param( 'object_id', $this->postId );
		$request->set_param( 'mode', 'live' );
		$request->set_param( 'keyphrase', 'rank tracker' );
		return $request;
	}

	private function controller( int $code, string $body ): PagesController {
		return new PagesController( fetcher: $this->fetcher( $code, $body ) );
	}

	private function fetcher( int $code, string $body ): LoopbackFetcher {
		return new LoopbackFetcher(
			policy: new FetchPolicy( static fn (): array => array( '93.184.216.34' ) ),
			transport: static fn (): array => array(
				'code'         => $code,
				'location'     => '',
				'body'         => $body,
				'content_type' => 'text/html',
			)
		);
	}
}
