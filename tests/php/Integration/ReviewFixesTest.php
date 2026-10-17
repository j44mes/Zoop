<?php
/**
 * Regression tests for the Phase 1 review.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Content\FetchPolicy;
use SEOStudio\Content\LoopbackFetcher;
use SEOStudio\Content\PageModel;
use SEOStudio\Content\Renderer;
use SEOStudio\Content\TemplateValues;
use SEOStudio\Core\Plugin;
use SEOStudio\Integrations\ActiveHead;
use SEOStudio\Rest\PagesController;
use SEOStudio\Storage\Indexability;
use SEOStudio\Storage\KeyphrasesIndex;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Storage\Tables;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Exercises WordPress storage, capabilities and public analysis paths.
 */
final class ReviewFixesTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		Plugin::init();
		Plugin::rest();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		update_option( 'home', 'https://example.test' );
		update_option( 'siteurl', 'https://example.test' );
		update_option( 'blog_public', '1' );
	}

	public function test_list_scores_only_the_current_focus_phrase(): void {
		$id = self::factory()->post->create( array( 'post_title' => 'Unique dashboard fixture', 'post_content' => '<p>alpha beta</p>' ) );
		update_post_meta( $id, '_seostudio_focus_keyphrase', 'alpha' );
		$request = new WP_REST_Request( 'POST', '/seostudio/v1/analyse/post/' . $id );
		$request->set_param( 'keyphrases', array( 'alpha', 'unrelated second phrase' ) );
		$result = rest_do_request( $request );
		$this->assertSame( 200, $result->get_status() );
		$scores = array_column( $result->get_data()['scores'], 'score', 'keyphrase' );
		$list = new WP_REST_Request( 'GET', '/seostudio/v1/pages' );
		$list->set_param( 'search', 'Unique dashboard fixture' );
		$item = rest_do_request( $list )->get_data()['items'][0];
		$this->assertSame( 'alpha', $item['keyphrase'] );
		$this->assertSame( $scores['alpha'], $item['onpage']['score'] );
		update_post_meta( $id, '_seostudio_focus_keyphrase', 'not yet analysed' );
		$this->assertNull( rest_do_request( $list )->get_data()['items'][0]['onpage']['score'] );
		delete_post_meta( $id, '_seostudio_focus_keyphrase' );
		$item = rest_do_request( $list )->get_data()['items'][0];
		$this->assertSame( '', $item['keyphrase'] );
		$this->assertNull( $item['onpage']['score'] );
		$list->set_param( 'has_keyphrase', true );
		$this->assertSame( 0, rest_do_request( $list )->get_data()['total'] );
	}

	public function test_validation_does_not_intercept_other_plugins(): void {
		add_action( 'rest_api_init', static function (): void {
			register_rest_route( 'other/v1', '/seo', array(
				'methods' => 'PUT',
				'callback' => static fn () => array( 'accepted' => true ),
				'permission_callback' => '__return_true',
			) );
		} );
		do_action( 'rest_api_init', rest_get_server() );
		$request = new WP_REST_Request( 'PUT', '/other/v1/seo' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '{"title":123,"canonical":"https://elsewhere.test"}' );
		$this->assertSame( array( 'accepted' => true ), rest_do_request( $request )->get_data() );
	}

	public function test_related_phrases_follow_rest_updates_and_deletion(): void {
		$id = self::factory()->post->create();
		$index = new KeyphrasesIndex();
		$request = new WP_REST_Request( 'PUT', '/seostudio/v1/posts/' . $id . '/seo' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array(
			'focus_keyphrase' => 'alpha',
			'related_keyphrases' => array( array( 'keyphrase' => 'ALPHA' ), array( 'keyphrase' => 'beta' ) ),
		) ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$this->assertSame( array( array( 'post_id' => $id, 'is_primary' => true ) ), $index->rowsFor( 'alpha' ) );
		$this->assertSame( array( array( 'post_id' => $id, 'is_primary' => false ) ), $index->rowsFor( 'beta' ) );
		delete_post_meta( $id, '_seostudio_focus_keyphrase' );
		$this->assertFalse( $index->rowsFor( 'alpha' )[0]['is_primary'] );
		update_post_meta( $id, '_seostudio_related_keyphrases', array( array( 'keyphrase' => 'gamma' ) ) );
		$this->assertSame( array(), $index->rowsFor( 'alpha' ) );
		$this->assertSame( array(), $index->rowsFor( 'beta' ) );
		$this->assertSame( array( $id ), $index->postIdsFor( 'gamma' ) );
		wp_delete_post( $id, true );
		$this->assertSame( array(), $index->rowsFor( 'gamma' ) );
	}

	public function test_live_and_content_apply_the_same_selector_precedence(): void {
		// wp-env defines WP_HOME as localhost, which the fetch policy blocks; pre_option wins over the constant.
		$home = static fn () => 'https://example.test';
		add_filter( 'pre_option_home', $home );
		add_filter( 'pre_option_siteurl', $home );
		$html = '<html><head><title>Observed title</title></head><body><main>Noise</main><div class="chosen"><p>Chosen copy</p></div><section>Site copy</section></body></html>';
		$id = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => $html ) );
		$settings = get_option( 'seostudio_settings' );
		$settings['content_selectors'] = array( 'post' => 'section' );
		update_option( 'seostudio_settings', $settings );
		$filter = static fn () => array( 'post' => '.chosen' );
		add_filter( 'seostudio/content_selectors', $filter );
		$fetcher = new LoopbackFetcher(
			policy: new FetchPolicy( static fn () => array( '93.184.216.34' ) ),
			transport: static fn () => array( 'code' => 200, 'location' => '', 'body' => $html )
		);
		$controller = new PagesController( fetcher: $fetcher );
		try {
			foreach ( array( '.chosen', 'section' ) as $selector ) {
				if ( 'section' === $selector ) {
					update_post_meta( $id, '_seostudio_content_selector', 'section' );
				}
				$content = ( new Renderer() )->renderPost( $id );
				$request = new WP_REST_Request( 'POST' );
				$request->set_param( 'object_type', 'post' );
				$request->set_param( 'object_id', $id );
				$request->set_param( 'mode', 'live' );
				$request->set_param( 'keyphrase', 'copy' );
				$response = $controller->analyseObject( $request );
				$this->assertInstanceOf( \WP_REST_Response::class, $response );
				$model = $response->get_data()['page_model'];
				$this->assertSame( $selector, $model['content_selector_used'] );
				$this->assertSame( $content->bodyText, $model['body_text'] );
				$this->assertSame( 'observed_html', $model['metadata_status'] );
				$this->assertSame( 'Observed title', $model['title_tag'] );
				$this->assertSame( 'estimated', $content->metadataStatus );
			}
		} finally {
			remove_filter( 'seostudio/content_selectors', $filter );
			remove_filter( 'pre_option_home', $home );
			remove_filter( 'pre_option_siteurl', $home );
		}
	}

	public function test_plugin_templates_use_the_actual_post_type(): void {
		$id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'wpseo_titles', array( 'title-post' => 'Wrong', 'title-page' => 'Page template', 'metadesc-page' => 'Page description' ) );
		update_option( 'seopress_titles_option_name', array( 'seopress_titles_single_titles' => array( 'page' => array( 'title' => 'Page template', 'description' => 'Page description' ) ) ) );
		// Exercise option reading without loading a foreign plugin globally into this suite.
		$method = new \ReflectionMethod( ActiveHead::class, 'stored' );
		$method->setAccessible( true );
		foreach ( array( 'yoast', 'seopress' ) as $plugin ) {
			$stored = $method->invoke( new ActiveHead(), $plugin, $id );
			$this->assertSame( 'Page template', $stored['title_template'] );
			$this->assertSame( 'Page description', $stored['description_template'] );
		}
	}

	public function test_indexability_distinguishes_blocked_and_unverified_pages(): void {
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->assertNull( Indexability::forPost( $id, new PageModel() ) );
		$this->assertTrue( Indexability::forPost( $id, new PageModel( renderMode: 'live', metadataStatus: 'observed_html' ) ) );
		foreach ( array( 'noindex, follow', 'none', 'googlebot: noindex' ) as $robots ) {
			$this->assertFalse( Indexability::forPost( $id, new PageModel( robots: $robots, renderMode: 'live' ) ) );
		}
		update_option( 'blog_public', '0' );
		$this->assertFalse( Indexability::forPost( $id, new PageModel( renderMode: 'live', metadataStatus: 'observed_html' ) ) );
		update_option( 'blog_public', '1' );
		wp_update_post( array( 'ID' => $id, 'post_password' => 'secret' ) );
		$this->assertFalse( Indexability::forPost( $id, new PageModel( renderMode: 'live', metadataStatus: 'observed_html' ) ) );
		wp_update_post( array( 'ID' => $id, 'post_password' => '', 'post_status' => 'draft' ) );
		$this->assertFalse( Indexability::forPost( $id, new PageModel( renderMode: 'live', metadataStatus: 'observed_html' ) ) );
	}

	public function test_upgrade_invalidates_old_indexability_claims(): void {
		global $wpdb;
		$table = Tables::pages();
		$id = self::factory()->post->create();
		$page = ( new PagesRepository() )->storeModel( $id, new PageModel( url: 'https://example.test/upgrade' ), '' );
		$wpdb->query( "UPDATE {$table} SET is_indexable = 1" );
		$wpdb->query( "ALTER TABLE {$table} MODIFY is_indexable tinyint(1) NOT NULL DEFAULT 1" );
		$settings = get_option( 'seostudio_settings' );
		$settings['schema_version'] = 1;
		update_option( 'seostudio_settings', $settings );
		\SEOStudio\Core\Upgrader::maybeUpgrade();
		$this->assertSame( \SEOStudio\Core\Upgrader::CURRENT, get_option( 'seostudio_settings' )['schema_version'] );
		$this->assertNull( ( new PagesRepository() )->find( $page )['is_indexable'] );
	}

	public function test_pagination_bounds_model_and_details_loading(): void {
		$repository = new PagesRepository();
		for ( $i = 0; $i < 205; ++$i ) {
			$id = self::factory()->post->create( array( 'post_title' => sprintf( 'Pagination fixture %03d', $i ) ) );
			$repository->storeModel( $id, new PageModel( url: 'https://example.test/page-' . $id, bodyText: str_repeat( 'large model ', 100 ) ), '' );
		}
		$queries = array();
		$observe = static function ( string $query ) use ( &$queries ): string {
			if ( str_contains( $query, 'FROM ' . Tables::pages() . ' pages' ) ) {
				$queries[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $observe );
		try {
			$request = new WP_REST_Request( 'GET', '/seostudio/v1/pages' );
			$request->set_param( 'search', 'Pagination fixture' );
			$request->set_param( 'orderby', 'title' );
			$request->set_param( 'order', 'asc' );
			$request->set_param( 'per_page', 10 );
			$request->set_param( 'page', 21 );
			$data = rest_do_request( $request )->get_data();
		} finally {
			remove_filter( 'query', $observe );
		}
		$this->assertSame( 205, $data['total'] );
		$this->assertCount( 5, $data['items'] );
		$this->assertSame( 'Pagination fixture 200', $data['items'][0]['title'] );
		// One aggregate for the total and summary, one bounded page query.
		$this->assertCount( \SEOStudio\Core\Capabilities::needsObjectChecks() ? 3 : 2, $queries );
		foreach ( $queries as $query ) {
			$this->assertStringNotContainsString( 'pages.*', $query );
			$this->assertStringNotContainsString( 'page_model', $query );
		}
		$this->assertStringContainsString( 'COUNT(*)', $queries[ count( $queries ) - 2 ] );
		$this->assertStringNotContainsString( 'scores.details', $queries[0] );
		$this->assertStringContainsString( 'LIMIT 10 OFFSET 200', $queries[ count( $queries ) - 1 ] );
	}
}
