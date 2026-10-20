<?php
/**
 * WordPress integration coverage for the Phase 1 correction.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Cli\Commands;
use SEOStudio\Content\PageModel;
use SEOStudio\Core\Plugin;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Storage\Tables;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Activation, REST permissions, metadata, and the CLI.
 */
final class PhaseOneTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		do_action( 'init' );
		do_action( 'rest_api_init' );
	}

	public function test_activation_creates_all_four_tables(): void {
		global $wpdb;

		foreach ( array( Tables::pages(), Tables::scores(), Tables::keyphrases(), Tables::auditRuns() ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
		}
		delete_option( 'seostudio_settings' );
		Plugin::activate();
		foreach ( array( Tables::pages(), Tables::scores(), Tables::keyphrases(), Tables::auditRuns() ) as $table ) {
			$wpdb->suppress_errors( true );
			$columns = $wpdb->get_results( "DESCRIBE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
			$wpdb->suppress_errors( false );
			$this->assertNotEmpty( $columns, $table );
		}
	}

	public function test_registered_meta_is_hidden_from_the_core_rest_api(): void {
		$keys = get_registered_meta_keys( 'post' );
		$this->assertArrayHasKey( '_seostudio_focus_keyphrase', $keys );
		$this->assertArrayHasKey( '_seostudio_title', $keys );
		$this->assertNotFalse( $keys['_seostudio_title']['show_in_rest'] );

		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Public page',
				'post_content' => 'Visible copy.',
			)
		);
		update_post_meta( $post_id, '_seostudio_focus_keyphrase', 'rank tracker' );

		wp_set_current_user( 0 );
		$response = $this->dispatch( new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id ) );
		$data     = $response->get_data();
		$encoded  = (string) wp_json_encode( $data );
		$this->assertStringNotContainsString( '_seostudio_', $encoded );
		$this->assertStringNotContainsString( 'rank tracker', $encoded );
	}

	public function test_anonymous_and_subscriber_cannot_read_seo_fields(): void {
		$post_id = self::factory()->post->create();

		wp_set_current_user( 0 );
		$anonymous = $this->dispatch( new WP_REST_Request( 'GET', '/seostudio/v1/posts/' . $post_id . '/seo' ) );
		$this->assertSame( 401, $anonymous->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$subscriber = $this->dispatch( new WP_REST_Request( 'GET', '/seostudio/v1/posts/' . $post_id . '/seo' ) );
		$this->assertSame( 403, $subscriber->get_status() );
	}

	public function test_editor_can_read_analyse_and_update_and_stranger_cannot(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_author'  => $editor,
				'post_status'  => 'draft',
				'post_content' => '<p>rank tracker belongs in this draft.</p>',
				'post_title'   => 'Draft',
			)
		);

		wp_set_current_user( $author );
		foreach ( array( 'GET', 'PUT' ) as $method ) {
			$request = new WP_REST_Request( $method, '/seostudio/v1/posts/' . $post_id . '/seo' );
			if ( 'PUT' === $method ) {
				$request->set_header( 'Content-Type', 'application/json' );
				$request->set_body( (string) wp_json_encode( array( 'title' => 'rank tracker draft' ) ) );
			}
			$this->assertSame( 403, $this->dispatch( $request )->get_status(), $method );
		}
		$analyse = new WP_REST_Request( 'POST', '/seostudio/v1/analyse/post/' . $post_id );
		$analyse->set_header( 'Content-Type', 'application/json' );
		$analyse->set_body( (string) wp_json_encode( array( 'keyphrase' => 'rank tracker' ) ) );
		$this->assertSame( 403, $this->dispatch( $analyse )->get_status() );

		wp_set_current_user( $editor );
		$read = $this->dispatch( new WP_REST_Request( 'GET', '/seostudio/v1/posts/' . $post_id . '/seo' ) );
		$this->assertSame( 200, $read->get_status() );

		$update = new WP_REST_Request( 'PUT', '/seostudio/v1/posts/' . $post_id . '/seo' );
		$update->set_header( 'Content-Type', 'application/json' );
		$update->set_body( (string) wp_json_encode( array( 'title' => 'rank tracker draft' ) ) );
		$this->assertSame( 200, $this->dispatch( $update )->get_status() );

		$allowed = new WP_REST_Request( 'POST', '/seostudio/v1/analyse/post/' . $post_id );
		$allowed->set_header( 'Content-Type', 'application/json' );
		$allowed->set_body( (string) wp_json_encode( array( 'keyphrase' => 'rank tracker' ) ) );
		$scored = $this->dispatch( $allowed );
		$this->assertSame( 200, $scored->get_status() );
		$this->assertArrayHasKey( 'page_model', $scored->get_data() );
	}

	public function test_list_hides_drafts_the_user_cannot_edit(): void {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$draft  = self::factory()->post->create(
			array(
				'post_author'  => $editor,
				'post_status'  => 'draft',
				'post_title'   => 'Secret draft',
				'post_content' => 'Hidden draft body.',
			)
		);
		$public = self::factory()->post->create(
			array(
				'post_author'  => $author,
				'post_status'  => 'publish',
				'post_title'   => 'Visible post',
				'post_content' => 'Public body.',
			)
		);

		wp_set_current_user( $editor );
		foreach ( array( $draft, $public ) as $post_id ) {
			$request = new WP_REST_Request( 'POST', '/seostudio/v1/analyse/post/' . $post_id );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( array( 'keyphrase' => 'visible' ) ) );
			$this->assertSame( 200, $this->dispatch( $request )->get_status() );
		}

		wp_set_current_user( $author );
		$list = $this->dispatch( new WP_REST_Request( 'GET', '/seostudio/v1/pages' ) );
		$this->assertSame( 200, $list->get_status() );
		$titles = array_column( $list->get_data()['items'], 'title' );
		$this->assertNotContains( 'Secret draft', $titles );
		$this->assertContains( 'Visible post', $titles );
	}

	public function test_first_analysis_returns_a_score_and_the_list_includes_it(): void {
		$user    = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_author'  => $user,
				'post_title'   => 'Fresh post',
				'post_content' => '<p>rank tracker is the subject of this fresh post.</p>',
			)
		);
		wp_set_current_user( $user );

		$request = new WP_REST_Request( 'POST', '/seostudio/v1/analyse/post/' . $post_id );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'keyphrase' => 'rank tracker' ) ) );
		$response = $this->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertNotEmpty( $data['scores'] );
		$this->assertSame( 'rank tracker', $data['scores'][0]['keyphrase'] );

		$list = $this->dispatch( new WP_REST_Request( 'GET', '/seostudio/v1/pages' ) );
		$this->assertContains( (int) $data['page_id'], array_column( $list->get_data()['items'], 'page_id' ) );
	}

	public function test_page_model_survives_a_database_round_trip(): void {
		$post_id = self::factory()->post->create();
		$model   = PageModel::fromArray(
			array(
				'url'                   => 'https://example.test/rank-tracker',
				'slug'                  => 'rank-tracker',
				'title_tag'             => 'Rank tracker',
				'meta_description'      => 'Track rankings.',
				'canonical'             => 'https://example.test/rank-tracker',
				'robots'                => 'index, follow',
				'h1'                    => array( 'Rank tracker' ),
				'headings'              => array(
					array(
						'level' => 2,
						'text'  => 'Method',
						'order' => 1,
					),
				),
				'body_text'             => 'A full paragraph about rankings.',
				'word_count'            => 5,
				'paragraphs'            => array( 'A full paragraph about rankings.' ),
				'sentences'             => array( 'A full paragraph about rankings.' ),
				'links'                 => array(
					array(
						'href'     => 'https://example.test/other',
						'anchor'   => 'Other',
						'internal' => true,
						'nofollow' => false,
					),
				),
				'images'                => array(
					array(
						'src'     => 'https://example.test/chart.png',
						'alt'     => 'Chart',
						'in_body' => true,
					),
				),
				'has_external_css'      => true,
				'html_bytes'            => 1200,
				'visible_text_bytes'    => 40,
				'is_https'              => true,
				'lang'                  => 'en',
				'render_mode'           => 'live',
				'content_selector_used' => 'main',
				'rendered_at'           => '2026-10-17T00:00:00+00:00',
				'page_type'             => 'post',
			)
		);

		$repository = new PagesRepository();
		$page_id    = $repository->storeModel( $post_id, $model, $model->inputHash() );
		$loaded     = $repository->loadModel( $post_id );
		$this->assertNotNull( $loaded );
		$this->assertSame( $model->toArray(), $loaded->toArray() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$response = $this->dispatch( new WP_REST_Request( 'GET', '/seostudio/v1/pages/' . $page_id ) );
		$this->assertSame( $model->toArray(), $response->get_data()['page_model'] );
	}

	public function test_invalid_seo_fields_name_the_field(): void {
		$user    = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id = self::factory()->post->create( array( 'post_author' => $user ) );
		wp_set_current_user( $user );
		update_option( 'home', 'https://example.test' );
		update_option( 'siteurl', 'https://example.test' );

		$cases = array(
			'focus_keyphrase'    => str_repeat( 'a', 256 ),
			'title'              => str_repeat( 'a', 301 ),
			'description'        => str_repeat( 'a', 501 ),
			'canonical'          => 'https://evil.test/page',
			'related_keyphrases' => array(
				array( 'keyphrase' => 'one' ),
				array( 'keyphrase' => 'two' ),
				array( 'keyphrase' => 'three' ),
				array( 'keyphrase' => 'four' ),
				array( 'keyphrase' => 'five' ),
			),
			'synonyms'           => 'nope',
			'robots'             => array( 'index' => 'yes' ),
			'og_title'           => str_repeat( 'a', 301 ),
			'og_description'     => str_repeat( 'a', 501 ),
			'og_image'           => -1,
			'cornerstone'        => 'yes',
			'content_selector'   => '<script>',
		);

		foreach ( $cases as $field => $value ) {
			$request = new WP_REST_Request( 'PUT', '/seostudio/v1/posts/' . $post_id . '/seo' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( array( $field => $value ) ) );
			$response = $this->dispatch( $request );
			$this->assertSame( 400, $response->get_status(), $field );
			$this->assertSame( 'seostudio_validation', $response->get_data()['code'], $field );
			$this->assertSame( $field, $response->get_data()['data']['field'] );
		}
	}

	public function test_keyphrase_index_follows_rest_writes(): void {
		$user    = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id = self::factory()->post->create(
			array(
				'post_author' => $user,
				'post_title'  => 'Indexed',
			)
		);
		wp_set_current_user( $user );

		$save = new WP_REST_Request( 'PUT', '/seostudio/v1/posts/' . $post_id . '/seo' );
		$save->set_header( 'Content-Type', 'application/json' );
		$save->set_body( (string) wp_json_encode( array( 'focus_keyphrase' => 'rank tracker' ) ) );
		$this->assertSame( 200, $this->dispatch( $save )->get_status() );

		$usage = new WP_REST_Request( 'GET', '/seostudio/v1/keyphrases/usage' );
		$usage->set_param( 'keyphrase', 'rank tracker' );
		$usage = $this->dispatch( $usage );
		$this->assertSame( array( $post_id ), array_column( $usage->get_data()['posts'], 'post_id' ) );

		$clear = new WP_REST_Request( 'PUT', '/seostudio/v1/posts/' . $post_id . '/seo' );
		$clear->set_header( 'Content-Type', 'application/json' );
		$clear->set_body( (string) wp_json_encode( array( 'focus_keyphrase' => '' ) ) );
		$this->assertSame( 200, $this->dispatch( $clear )->get_status() );

		$again = new WP_REST_Request( 'GET', '/seostudio/v1/keyphrases/usage' );
		$again->set_param( 'keyphrase', 'rank tracker' );
		$again = $this->dispatch( $again );
		$this->assertSame( array(), $again->get_data()['posts'] );
	}

	public function test_cli_analyse_scores_a_fresh_post(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			require dirname( __DIR__, 3 ) . '/stubs/wp-cli.php';
		}

		$post_id = self::factory()->post->create(
			array(
				'post_content' => '<p>rank tracker is worth scoring from the command line.</p>',
				'post_title'   => 'CLI post',
			)
		);
		Commands::analyse( array( (string) $post_id, 'rank tracker' ) );
		$this->assertNotNull( ( new PagesRepository() )->findByObject( 'post', $post_id ) );
	}

	private function dispatch( WP_REST_Request $request ): WP_REST_Response {
		$result = rest_do_request( $request );
		if ( $result instanceof WP_Error ) {
			return rest_convert_error_to_response( $result );
		}

		return $result;
	}
}
