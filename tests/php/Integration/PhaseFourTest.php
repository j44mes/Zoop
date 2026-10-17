<?php
/**
 * Owner mode, schema output, and importers.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Core\Plugin;
use SEOStudio\Core\Settings;
use SEOStudio\Integrations\Detector;
use SEOStudio\Integrations\Importers\RankMath;
use SEOStudio\Integrations\Importers\SeoPress;
use SEOStudio\Integrations\Importers\Yoast;
use SEOStudio\Jobs\ImportBatch;
use SEOStudio\Output\HeadOutput;
use SEOStudio\Output\RobotsFilter;
use SEOStudio\Storage\AuditRunsRepository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Head output stays off in Analysis-only mode and while another plugin is forced active.
 */
final class PhaseFourTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Detector::clearForce();
		Plugin::activate();
		do_action( 'init' );
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		Detector::clearForce();
		parent::tear_down();
	}

	public function test_analysis_mode_prints_no_studio_description(): void {
		$this->saveMode( 'analysis' );
		$post_id = $this->page();
		$this->go_to( (string) get_permalink( $post_id ) );
		$this->assertSame( '', $this->head() );
	}

	public function test_owner_mode_prints_description_article_and_v2_nodes(): void {
		$this->saveMode( 'owner' );
		$post_id = $this->page();
		update_post_meta( $post_id, '_seostudio_is_news', '1' );
		update_post_meta( $post_id, '_seostudio_video_url', 'https://cdn.example.test/clip.mp4' );
		$this->go_to( (string) get_permalink( $post_id ) );

		$html = $this->head();
		$this->assertStringContainsString( 'A rank tracker page.', $html );
		$this->assertStringContainsString( '"@type":"NewsArticle"', $html );
		$this->assertStringContainsString( '"@type":"VideoObject"', $html );
		$this->assertStringContainsString( '"@type":"BreadcrumbList"', $html );
		$this->assertStringNotContainsString( '"@type":"Article"', $html );

		$title = apply_filters( 'pre_get_document_title', '' );
		$this->assertStringContainsString( 'Rank tracker', (string) $title );
	}

	public function test_the_home_page_can_print_a_local_business(): void {
		$settings                     = Settings::all();
		$settings['mode']             = 'owner';
		$settings['schema_version']   = 2;
		$settings['home_description'] = 'Studio home description';
		$settings['local_business']   = array(
			'enabled'   => true,
			'name'      => 'Example Shop',
			'street'    => '1 High Street',
			'city'      => 'York',
			'telephone' => '01234',
		);
		update_option( Settings::OPTION, $settings, true );
		$this->go_to( home_url( '/' ) );

		$html = $this->head();
		$this->assertStringContainsString( 'Studio home description', $html );
		$this->assertStringContainsString( '"@type":"LocalBusiness"', $html );
		$this->assertStringContainsString( '"@type":"WebSite"', $html );
	}

	public function test_a_static_front_page_prints_the_homepage_graph(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Static home',
				'post_content' => '<p>Page body.</p>',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );
		$settings                     = Settings::all();
		$settings['mode']             = 'owner';
		$settings['schema_version']   = 2;
		$settings['home_description'] = 'Studio home description';
		$settings['local_business']   = array(
			'enabled'   => true,
			'name'      => 'Example Shop',
			'street'    => '1 High Street',
			'city'      => 'York',
			'telephone' => '01234',
		);
		update_option( Settings::OPTION, $settings, true );
		$this->go_to( home_url( '/' ) );

		$this->assertTrue( is_front_page() );
		$this->assertTrue( is_singular() );
		$html = $this->head();
		$this->assertStringContainsString( 'Studio home description', $html );
		$this->assertStringContainsString( '"@type":"WebSite"', $html );
		$this->assertStringContainsString( '"@type":"Organization"', $html );
		$this->assertStringContainsString( '"@type":"LocalBusiness"', $html );
		$this->assertStringNotContainsString( '"@type":"Article"', $html );
	}

	public function test_a_null_robots_field_inherits_the_site_default(): void {
		$this->saveMode( 'owner' );
		$settings                     = Settings::all();
		$settings['mode']             = 'owner';
		$settings['schema_version']   = 2;
		$settings['default_robots']   = array(
			'index'             => false,
			'follow'            => true,
			'max_snippet'       => 80,
			'max_image_preview' => 'large',
		);
		update_option( Settings::OPTION, $settings, true );
		$post_id = $this->page();
		update_post_meta(
			$post_id,
			'_seostudio_robots',
			array(
				'index'             => null,
				'follow'            => true,
				'max_snippet'       => null,
				'max_image_preview' => null,
			)
		);
		$this->go_to( (string) get_permalink( $post_id ) );

		$robots = ( new RobotsFilter() )->filter( array() );
		$this->assertTrue( $robots['noindex'] );
		$this->assertArrayNotHasKey( 'nofollow', $robots );
		$this->assertSame( '80', $robots['max-snippet'] );
		$this->assertSame( 'large', $robots['max-image-preview'] );
	}

	public function test_saved_social_copy_is_printed(): void {
		$this->saveMode( 'owner' );
		$post_id = $this->page();
		update_post_meta( $post_id, '_seostudio_og_title', 'Social headline' );
		update_post_meta( $post_id, '_seostudio_og_description', 'Social blurb' );
		$this->go_to( (string) get_permalink( $post_id ) );

		$html = $this->head();
		$this->assertStringContainsString( 'content="Social headline"', $html );
		$this->assertStringContainsString( 'content="Social blurb"', $html );
		$this->assertStringContainsString( 'name="description" content="A rank tracker page."', $html );
	}

	public function test_an_import_keeps_unresolved_template_warnings(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Token title' ) );
		update_post_meta( $post_id, '_yoast_wpseo_title', '%%unknown_token%%' );

		$run_id = ( new ImportBatch() )->start( 'yoast' );
		$run    = ( new AuditRunsRepository() )->find( $run_id );
		$this->assertIsArray( $run );
		$report = json_decode( (string) $run['report'], true );
		$this->assertIsArray( $report );
		$this->assertGreaterThan( 0, (int) ( $report['needs_review'] ?? 0 ) );
		$this->assertStringContainsString( '%%unknown_token%%', (string) get_post_meta( $post_id, '_seostudio_title', true ) );
	}

	public function test_owner_mode_is_refused_while_a_plugin_is_detected(): void {
		Detector::force(
			array(
				'slug'    => 'yoast',
				'name'    => 'Yoast SEO',
				'version' => 'test',
			)
		);
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$request = new WP_REST_Request( 'PUT', '/seostudio/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'mode' => 'owner' ) ) );
		$response = rest_do_request( $request );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'analysis', Settings::all()['mode'] );
		$this->assertFalse( ( new HeadOutput() )->publishing() );
	}

	public function test_yoast_import_copies_meta_and_leaves_the_source_untouched(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Imported title' ) );
		update_post_meta( $post_id, '_yoast_wpseo_title', '%%title%% %%sep%% %%sitename%%' );
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', 'Yoast description' );
		update_post_meta( $post_id, '_yoast_wpseo_focuskw', 'rank tracker' );

		$result = ( new Yoast() )->import( 0, 200 );
		$this->assertSame( 1, $result['processed'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( '%%title%% %%sep%% %%sitename%%', get_post_meta( $post_id, '_yoast_wpseo_title', true ) );
		$this->assertSame( 'Yoast description', get_post_meta( $post_id, '_seostudio_description', true ) );
		$this->assertSame( 'rank tracker', get_post_meta( $post_id, '_seostudio_focus_keyphrase', true ) );
		$this->assertStringContainsString( 'Imported title', (string) get_post_meta( $post_id, '_seostudio_title', true ) );
	}

	public function test_rank_math_and_seopress_imports_copy_their_own_keys(): void {
		$rank = self::factory()->post->create();
		update_post_meta( $rank, 'rank_math_description', 'Rank description' );
		update_post_meta( $rank, 'rank_math_focus_keyword', 'alpha, beta' );
		( new RankMath() )->import( 0, 50 );
		$this->assertSame( 'Rank description', get_post_meta( $rank, '_seostudio_description', true ) );
		$this->assertSame( 'alpha', get_post_meta( $rank, '_seostudio_focus_keyphrase', true ) );
		$this->assertSame( 'alpha, beta', get_post_meta( $rank, 'rank_math_focus_keyword', true ) );

		$press = self::factory()->post->create();
		update_post_meta( $press, '_seopress_titles_desc', 'Press description' );
		update_post_meta( $press, '_seopress_robots_index', 'yes' );
		( new SeoPress() )->import( 0, 50 );
		$this->assertSame( 'Press description', get_post_meta( $press, '_seostudio_description', true ) );
		$robots = get_post_meta( $press, '_seostudio_robots', true );
		$this->assertIsArray( $robots );
		$this->assertFalse( $robots['index'] );
		$this->assertSame( 'yes', get_post_meta( $press, '_seopress_robots_index', true ) );
	}

	private function saveMode( string $mode ): void {
		$settings                   = Settings::all();
		$settings['mode']           = $mode;
		$settings['schema_version'] = 2;
		update_option( Settings::OPTION, $settings, true );
	}

	private function page(): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Rank tracker',
				'post_status'  => 'publish',
				'post_content' => '<p>Body copy.</p>',
				'post_excerpt' => 'Excerpt.',
			)
		);
		update_post_meta( $post_id, '_seostudio_description', 'A rank tracker page.' );
		update_post_meta( $post_id, '_seostudio_title', '%%title%% guide' );
		return $post_id;
	}

	private function head(): string {
		ob_start();
		( new HeadOutput() )->render();
		return (string) ob_get_clean();
	}
}
