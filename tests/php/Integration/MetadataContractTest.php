<?php
/**
 * SEO title and description contract.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Appearance\Decision;
use SEOStudio\Appearance\EditorMeta;
use SEOStudio\Appearance\Fields;
use SEOStudio\Appearance\ProposalStore;
use SEOStudio\Content\Extractor;
use SEOStudio\Content\FetchPolicy;
use SEOStudio\Content\LoopbackFetcher;
use SEOStudio\Content\Renderer;
use SEOStudio\Core\Settings;
use SEOStudio\Integrations\Detector;
use SEOStudio\Integrations\Importers\Yoast;
use SEOStudio\Output\HeadOutput;
use SEOStudio\Output\TitleFilter;
use SEOStudio\Rest\AppearanceController;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Update, apply, verify, and restore share one proposal and the observed HTML.
 */
final class MetadataContractTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Detector::clearForce();
		do_action( 'rest_api_init' );
		EditorMeta::registerMeta();
		$settings         = Settings::all();
		$settings['mode'] = 'owner';
		update_option( Settings::OPTION, $settings );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$_POST = array();
		unset( $GLOBALS['seostudio_classic_notice'], $_GET['seostudio_notice'] );
		Detector::clearForce();
		parent::tear_down();
	}

	public function test_two_saves_with_the_same_revision_keep_the_first_text(): void {
		$post_id = self::factory()->post->create();
		$store   = new ProposalStore();

		$first  = $store->commit( $post_id, 'First title', 'First description', 0 );
		$second = $store->commit( $post_id, 'Second title', 'Second description', 0 );

		$this->assertTrue( $first['ok'] );
		$this->assertSame( 1, $first['revision'] );
		$this->assertFalse( $second['ok'] );
		$this->assertSame( 409, $second['status'] );
		$this->assertSame( 'seostudio_stale', $second['code'] );
		$this->assertSame( 'First title', get_post_meta( $post_id, Fields::TITLE, true ) );
		$this->assertSame( 'First description', get_post_meta( $post_id, Fields::DESCRIPTION, true ) );
		$this->assertSame( 1, $store->revision( $post_id ) );
	}

	public function test_a_held_lock_fails_closed_and_writes_nothing(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Original' ) );
		$lock    = $this->externalLock( $post_id );
		$result  = ( new ProposalStore() )->commit( $post_id, 'Locked title', 'Locked description', 0 );
		$lock();

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 409, $result['status'] );
		$this->assertSame( '', get_post_meta( $post_id, Fields::TITLE, true ) );
		$this->assertSame( 0, ( new ProposalStore() )->revision( $post_id ) );
		$this->assertSame( 'Original', get_post( $post_id )->post_title );
	}

	public function test_a_thrown_write_releases_the_lock_and_keeps_the_stored_proposal(): void {
		$post_id = self::factory()->post->create();
		$store   = new ProposalStore();
		$this->assertTrue( $store->commit( $post_id, 'Kept title', 'Kept description', 0 )['ok'] );
		$throw = static function (): bool {
			throw new \RuntimeException( 'disk full' );
		};
		add_filter( 'update_post_metadata', $throw, 10, 0 );
		try {
			$result = $store->commit( $post_id, 'Replacement title', 'Replacement description', 1 );
		} finally {
			remove_filter( 'update_post_metadata', $throw, 10 );
		}

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 500, $result['status'] );
		$this->assertSame( 'Kept title', get_post_meta( $post_id, Fields::TITLE, true ) );
		$this->assertSame( 1, $store->revision( $post_id ) );
		$locked = $this->tryLock( $post_id );
		$this->assertTrue( $locked['acquired'] );
		$locked['release']();
	}

	public function test_an_invalid_description_writes_neither_field_nor_the_post(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Door' ) );
		update_post_meta( $post_id, '_seostudio_title', 'Kept controlled' );
		$result = ( new ProposalStore() )->commit( $post_id, 'Valid title', str_repeat( 'd', 501 ), 0 );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 400, $result['status'] );
		$this->assertSame( '', get_post_meta( $post_id, Fields::TITLE, true ) );
		$this->assertSame( '', get_post_meta( $post_id, Fields::DESCRIPTION, true ) );
		$this->assertSame( 0, ( new ProposalStore() )->revision( $post_id ) );
		$this->assertSame( 'Kept controlled', get_post_meta( $post_id, '_seostudio_title', true ) );
		$this->assertSame( 'Door', get_post( $post_id )->post_title );
	}

	public function test_a_failed_database_write_rolls_the_pair_back(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, Fields::TITLE, 'Kept title' );
		$block = static function ( $check, $object_id, $meta_key ) {
			unset( $object_id );
			if ( Fields::DESCRIPTION === $meta_key ) {
				return false;
			}
			return $check;
		};
		add_filter( 'update_post_metadata', $block, 10, 3 );
		try {
			$result = ( new ProposalStore() )->commit( $post_id, 'Changed title', 'Changed description', 0 );
		} finally {
			remove_filter( 'update_post_metadata', $block, 10 );
		}

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 500, $result['status'] );
		$this->assertSame( 'Kept title', get_post_meta( $post_id, Fields::TITLE, true ) );
		$this->assertSame( '', get_post_meta( $post_id, Fields::DESCRIPTION, true ) );
		$this->assertSame( 0, ( new ProposalStore() )->revision( $post_id ) );
	}

	public function test_a_gutenberg_update_publishes_the_title_without_a_proposal_revision(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Original',
				'post_status' => 'publish',
			)
		);
		update_post_meta(
			$post_id,
			'_seostudio_robots',
			array(
				'index'  => true,
				'follow' => false,
			)
		);
		$saved = $this->coreUpdate(
			$post_id,
			array(
				'title' => 'Updated title',
				'meta'  => array(
					'_seostudio_title'       => 'Live SEO title',
					'_seostudio_description' => 'Live SEO description',
				),
			),
			null
		);

		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( 'Live SEO title', get_post_meta( $post_id, '_seostudio_title', true ) );
		$this->assertFalse( get_post_meta( $post_id, '_seostudio_robots', true )['follow'] );
		$html = $this->liveHead( $post_id );
		$this->assertStringContainsString( '<title>Live SEO title</title>', $html );
		$this->assertStringContainsString( 'content="Live SEO description"', $html );
		$this->assertStringContainsString( 'nofollow', $html );
	}

	public function test_a_classic_update_publishes_the_title_and_keeps_nofollow(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Original',
				'post_status' => 'publish',
			)
		);
		update_post_meta(
			$post_id,
			'_seostudio_robots',
			array(
				'index'  => true,
				'follow' => false,
			)
		);
		$this->classicFields( 'Classic title', 'Classic description' );
		$_POST['seostudio_robots_index']     = '1';
		$_POST['seostudio_robots_follow']    = '0';
		$_POST['_seostudio_focus_keyphrase'] = 'fire doors';
		$_POST['_seostudio_canonical']       = home_url( '/doors/' );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Visible title',
			)
		);
		unset( $_POST );

		$this->assertSame( 'Classic title', get_post_meta( $post_id, '_seostudio_title', true ) );
		$this->assertSame( 'Classic description', get_post_meta( $post_id, '_seostudio_description', true ) );
		$this->assertFalse( get_post_meta( $post_id, '_seostudio_robots', true )['follow'] );
		$this->assertStringContainsString( '<title>Classic title</title>', $this->liveHead( $post_id ) );
		$this->assertStringContainsString( 'nofollow', $this->liveHead( $post_id ) );
	}

	public function test_an_invalid_classic_nonce_leaves_the_title_and_other_saves_run(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_seostudio_title', 'Kept title' );
		$_POST = array(
			'seostudio_metabox_nonce' => 'not-the-nonce',
			'_seostudio_title'        => 'Hacked title',
			'_seostudio_description'  => 'Hacked description',
		);
		$ran = false;
		add_action(
			'save_post',
			static function () use ( &$ran ): void {
				$ran = true;
			},
			20,
			0
		);
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'The post save continues.',
			)
		);

		$this->assertTrue( $ran );
		$this->assertSame( 'Kept title', get_post_meta( $post_id, '_seostudio_title', true ) );
		$this->assertSame( 'nonce', $GLOBALS['seostudio_classic_notice'] );
		$location = apply_filters( 'redirect_post_location', 'post.php?post=' . $post_id . '&action=edit' );
		$this->assertStringContainsString( 'seostudio_notice=nonce', $location );
	}

	public function test_an_invalid_classic_title_is_rejected_and_other_saves_run(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_seostudio_title', 'Kept title' );
		$this->classicFields( str_repeat( 'a', 301 ), 'Still valid' );
		$ran = false;
		add_action(
			'save_post',
			static function () use ( &$ran ): void {
				$ran = true;
			},
			20,
			0
		);
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Content still saves.',
			)
		);

		$this->assertTrue( $ran );
		$this->assertSame( 'Kept title', get_post_meta( $post_id, '_seostudio_title', true ) );
		$this->assertSame( 'invalid', $GLOBALS['seostudio_classic_notice'] );
	}

	public function test_autosave_and_revision_saves_do_not_change_the_parent_title(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_seostudio_title', 'Parent title' );
		$this->classicFields( 'Autosave title', 'Autosave description' );
		wp_insert_post(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $post_id,
				'post_title'  => 'Revision',
			)
		);
		wp_insert_post(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $post_id,
				'post_name'   => $post_id . '-autosave-v1',
				'post_title'  => 'Autosave',
			)
		);

		$this->assertSame( 'Parent title', get_post_meta( $post_id, '_seostudio_title', true ) );
	}

	public function test_a_save_without_zoop_fields_leaves_the_title_untouched(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_seostudio_title', 'Untouched title' );
		$_POST = array();
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Quick edit',
			)
		);

		$this->assertSame( 'Quick edit', get_post( $post_id )->post_title );
		$this->assertSame( 'Untouched title', get_post_meta( $post_id, '_seostudio_title', true ) );
	}

	public function test_an_author_cannot_edit_another_authors_proposal_and_an_editor_can_load_a_page(): void {
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$other   = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id = self::factory()->post->create( array( 'post_author' => $author ) );
		wp_set_current_user( $other );
		$denied = $this->seoUpdate( $post_id, 'Borrowed title', 'Borrowed description' );
		$this->assertSame( 403, $denied->get_status() );
		$this->assertSame( '', get_post_meta( $post_id, '_seostudio_title', true ) );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$capabilities = rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/capabilities' ) );
		$this->assertSame( 200, $capabilities->get_status() );
		$this->assertFalse( $capabilities->get_data()['manage'] );
		$this->assertTrue( $capabilities->get_data()['edit_posts'] );
		$settings = rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/settings' ) );
		$this->assertSame( 403, $settings->get_status() );

		$own   = self::factory()->post->create( array( 'post_author' => $editor ) );
		$saved = $this->seoUpdate( $own, 'Editor title', 'Editor description' );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( 'Editor title', get_post_meta( $own, '_seostudio_title', true ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$hidden = rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/capabilities' ) );
		$this->assertSame( 403, $hidden->get_status() );
	}

	public function test_yoast_analysis_mode_does_not_overwrite_or_print_a_second_title(): void {
		Detector::force(
			array(
				'slug'    => 'yoast',
				'name'    => 'Yoast SEO',
				'version' => 'test',
			)
		);
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Door',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, '_yoast_wpseo_title', 'Yoast title' );
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', 'Yoast description' );
		( new Yoast() )->import( $post_id - 1, 5 );
		$saved = $this->seoUpdate( $post_id, 'Zoop title', 'Zoop description' );

		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( 'Yoast title', get_post_meta( $post_id, '_yoast_wpseo_title', true ) );
		$this->assertSame( 'Zoop title', get_post_meta( $post_id, '_seostudio_title', true ) );
		$html = $this->liveHead( $post_id );
		$this->assertStringNotContainsString( '<title>Zoop title</title>', $html );
		$this->assertStringNotContainsString( 'content="Zoop description"', $html );
		$this->assertSame( 1, substr_count( strtolower( $html ), '<title>' ) );
	}

	public function test_a_suggestion_stays_unpublished_until_apply_and_a_manual_edit_makes_it_stale(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Door',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, '_seostudio_title', 'Published title' );
		$suggestion = $this->request( 'PUT', $post_id, '/appearance', array(
			'title'             => 'Suggested title',
			'description'       => 'Suggested description',
			'proposal_revision' => 0,
		) );
		$this->assertSame( 200, $suggestion->get_status() );
		$this->assertSame( 'Published title', get_post_meta( $post_id, '_seostudio_title', true ) );
		$this->assertSame( 'Suggested title', get_post_meta( $post_id, Fields::TITLE, true ) );

		$manual = $this->seoUpdate( $post_id, 'Manual title', 'Manual description' );
		$this->assertSame( 200, $manual->get_status() );
		$this->assertStringContainsString( '<title>Manual title</title>', $this->liveHead( $post_id ) );

		$stale = $this->request( 'POST', $post_id, '/appearance/apply', array(
			'confirm'              => true,
			'proposal_revision'    => 1,
			'baseline_title'       => 'Manual title',
			'baseline_description' => 'Manual description',
		) );
		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 'seostudio_stale', $stale->as_error()->get_error_code() );
		$this->assertSame( 'Manual title', get_post_meta( $post_id, '_seostudio_title', true ) );
	}

	public function test_restore_before_apply_is_rejected(): void {
		$post_id = self::factory()->post->create();
		$result  = $this->request( 'POST', $post_id, '/appearance/restore', array(
			'confirm'              => true,
			'baseline_title'       => '',
			'baseline_description' => '',
		) );
		$this->assertSame( 400, $result->get_status() );
		$this->assertSame( 'seostudio_nothing_to_restore', $result->as_error()->get_error_code() );
	}

	public function test_analyse_does_not_write_metadata_and_an_upgrade_keeps_it(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_seostudio_title', 'Kept title' );
		update_post_meta( $post_id, '_seostudio_description', 'Kept description' );
		update_post_meta(
			$post_id,
			'_seostudio_robots',
			array(
				'index'  => true,
				'follow' => false,
			)
		);
		$analyse = new WP_REST_Request( 'POST', '/seostudio/v1/analyse/post/' . $post_id );
		$analyse->set_header( 'Content-Type', 'application/json' );
		$analyse->set_body( (string) wp_json_encode( array( 'keyphrase' => 'fire doors', 'mode' => 'content' ) ) );
		$this->assertSame( 200, rest_do_request( $analyse )->get_status() );
		$this->assertSame( 'Kept title', get_post_meta( $post_id, '_seostudio_title', true ) );
		$this->assertFalse( get_post_meta( $post_id, '_seostudio_robots', true )['follow'] );

		$settings                    = Settings::all();
		$settings['schema_version']  = 3;
		update_option( Settings::OPTION, $settings );
		\SEOStudio\Core\Upgrader::maybeUpgrade();
		$this->assertSame( 'Kept title', get_post_meta( $post_id, '_seostudio_title', true ) );
		$this->assertSame( 'Kept description', get_post_meta( $post_id, '_seostudio_description', true ) );
		$this->assertFalse( get_post_meta( $post_id, '_seostudio_robots', true )['follow'] );
	}

	public function test_a_draft_title_is_stored_and_printed_only_after_publish(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft', 'post_title' => 'Door' ) );
		$saved   = $this->seoUpdate( $post_id, 'Draft SEO title', 'Draft SEO description' );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( 'Draft SEO title', get_post_meta( $post_id, '_seostudio_title', true ) );

		wp_set_current_user( 0 );
		$hidden = $this->liveHead( $post_id );
		$this->assertStringNotContainsString( '<title>Draft SEO title</title>', $hidden );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		wp_publish_post( $post_id );
		$this->assertStringContainsString( '<title>Draft SEO title</title>', $this->liveHead( $post_id ) );
	}

	public function test_the_homepage_title_is_not_written_as_post_metadata(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );
		$admin = $this->seoUpdate( $page_id, 'Page title', 'Page description' );
		$this->assertSame( 400, $admin->get_status() );
		$this->assertSame( 'seostudio_homepage_settings', $admin->as_error()->get_error_code() );
		$this->assertSame( '', get_post_meta( $page_id, '_seostudio_title', true ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$editor = $this->seoUpdate( $page_id, 'Editor title', 'Editor description' );
		$this->assertSame( 403, $editor->get_status() );
		$this->assertSame( '', get_post_meta( $page_id, '_seostudio_title', true ) );
	}

	public function test_update_apply_verify_and_restore_use_the_saved_proposal_and_observed_html(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Door', 'post_status' => 'publish' ) );
		$saved   = $this->request( 'PUT', $post_id, '/appearance', array(
			'title'             => 'Journey title',
			'description'       => 'Journey description',
			'proposal_revision' => 0,
		) );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertSame( '', get_post_meta( $post_id, '_seostudio_title', true ) );
		$this->assertSame( 'Journey title', get_post_meta( $post_id, Fields::TITLE, true ) );

		$applied = $this->observe(
			$post_id,
			'<html><head><title>Journey title</title><meta name="description" content="Journey description"></head><body><main><p>Visible page copy for the check.</p></main></body></html>',
			'Journey title',
			'Journey description',
			'',
			''
		);
		$this->assertSame( 200, $applied->get_status() );
		$this->assertSame( 'Journey title', get_post_meta( $post_id, '_seostudio_title', true ) );
		$this->assertSame( 'Journey description', get_post_meta( $post_id, '_seostudio_description', true ) );
		$this->assertSame( 'verified', $applied->get_data()['verification']['status'] );
		$this->assertSame( 'live_html', $applied->get_data()['verification']['basis'] );
		$this->assertTrue( $applied->get_data()['verification']['html_matches'] );
		$this->assertSame( 'Journey title', $applied->get_data()['verification']['observed_title'] );

		$restored = $this->observe(
			$post_id,
			'<html><head><title>Door – Example</title></head><body><main><p>Visible page copy for the check.</p></main></body></html>',
			'',
			'',
			'Journey title',
			'Journey description',
			true
		);
		$this->assertSame( 200, $restored->get_status() );
		$this->assertSame( '', get_post_meta( $post_id, '_seostudio_title', true ) );
		$this->assertSame( 'live_html', $restored->get_data()['verification']['basis'] );
		$this->assertSame( 'not_verified', $restored->get_data()['verification']['title_state'] );
		$this->assertNull( $restored->get_data()['verification']['calculated_title'] );
		$this->assertSame( 'Door – Example', $restored->get_data()['verification']['observed_title'] );
		$this->assertFalse( $restored->get_data()['verification']['html_matches'] );
	}

	public function test_verification_states_follow_only_values_zoop_fully_determines(): void {
		$custom = self::factory()->post->create( array( 'post_title' => 'Door' ) );
		update_post_meta( $custom, '_seostudio_title', '%%title%% guide' );
		update_post_meta( $custom, '_seostudio_description', 'Explicit description' );
		$expected = ( new TitleFilter() )->expand( $custom, '%%title%% guide' );
		$matched  = $this->observe(
			$custom,
			'<html><head><title>' . $expected . '</title><meta name="description" content="Explicit description"></head><body><main><p>Visible page copy for the check.</p></main></body></html>',
			'%%title%% guide',
			'Explicit description',
			'%%title%% guide',
			'Explicit description'
		);
		$this->assertSame( 'verified', $matched->get_data()['verification']['status'] );
		$this->assertSame( $expected, $matched->get_data()['verification']['calculated_title'] );
		$this->assertTrue( $matched->get_data()['verification']['html_matches'] );

		$empty = self::factory()->post->create( array( 'post_title' => 'Door' ) );
		delete_post_meta( $empty, '_seostudio_title' );
		delete_post_meta( $empty, '_seostudio_description' );
		$theme = $this->observe(
			$empty,
			'<html><head><title>Door – Example</title></head><body><main><p>Visible page copy for the check.</p></main></body></html>',
			'',
			'',
			'',
			''
		);
		$this->assertSame( 'live_html', $theme->get_data()['verification']['basis'] );
		$this->assertSame( 'not_verified', $theme->get_data()['verification']['status'] );
		$this->assertSame( 'not_verified', $theme->get_data()['verification']['title_state'] );
		$this->assertNull( $theme->get_data()['verification']['calculated_title'] );
		$this->assertSame( 'Door – Example', $theme->get_data()['verification']['observed_title'] );
		$this->assertFalse( $theme->get_data()['verification']['html_matches'] );

		$different = self::factory()->post->create();
		update_post_meta( $different, '_seostudio_title', 'Saved title' );
		update_post_meta( $different, '_seostudio_description', 'Expected description' );
		$mismatch = $this->observe(
			$different,
			'<html><head><title>Saved title</title><meta name="description" content="Other description"></head><body><main><p>Visible page copy for the check.</p></main></body></html>',
			'Saved title',
			'Expected description',
			'Saved title',
			'Expected description'
		);
		$this->assertSame( 'mismatch', $mismatch->get_data()['verification']['status'] );
		$this->assertSame( 'mismatch', $mismatch->get_data()['verification']['description_state'] );
		$this->assertSame( 'live_html', $mismatch->get_data()['verification']['basis'] );
		$this->assertFalse( $mismatch->get_data()['verification']['html_matches'] );

		$excerpt = self::factory()->post->create();
		update_post_meta( $excerpt, '_seostudio_title', 'Saved title' );
		delete_post_meta( $excerpt, '_seostudio_description' );
		$generated = $this->observe(
			$excerpt,
			'<html><head><title>Saved title</title><meta name="description" content="Generated excerpt sentence"></head><body><main><p>Visible page copy for the check.</p></main></body></html>',
			'Saved title',
			'',
			'Saved title',
			''
		);
		$this->assertSame( 'live_html', $generated->get_data()['verification']['basis'] );
		$this->assertSame( 'not_verified', $generated->get_data()['verification']['description_state'] );
		$this->assertNull( $generated->get_data()['verification']['calculated_description'] );
		$this->assertSame( 'Generated excerpt sentence', $generated->get_data()['verification']['observed_description'] );
		$this->assertFalse( $generated->get_data()['verification']['html_matches'] );
		$this->assertFalse( is_front_page() );
	}

	public function test_homepage_verification_uses_homepage_settings_without_the_main_query(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Welcome',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );
		$settings                     = Settings::all();
		$settings['mode']             = 'owner';
		$settings['home_title']       = 'Home title';
		$settings['home_description'] = 'Home description';
		update_option( Settings::OPTION, $settings );
		$this->assertFalse( is_front_page() );

		$result = $this->observe(
			$page_id,
			'<html><head><title>Home title</title><meta name="description" content="Home description"></head><body><main><p>Visible page copy for the check.</p></main></body></html>',
			'Home title',
			'Home description',
			'Home title',
			'Home description'
		);

		$this->assertFalse( is_front_page() );
		$this->assertSame( 'verified', $result->get_data()['verification']['status'] );
		$this->assertSame( 'Home title', $result->get_data()['verification']['calculated_title'] );
		$this->assertSame( 'Home description', $result->get_data()['verification']['calculated_description'] );
		$this->assertSame( 'Home title', $result->get_data()['verification']['observed_title'] );
		$this->assertTrue( $result->get_data()['verification']['html_matches'] );
		$this->assertSame( 'Home title', Settings::all()['home_title'] );
	}

	/**
	 * Hold the proposal lock on another database connection, then release it.
	 *
	 * @return callable(): void
	 */
	private function externalLock( int $post_id ): callable {
		$connection = $this->secondConnection();
		$name       = 'seostudio_proposal_' . $post_id;
		$got        = $connection->query( "SELECT GET_LOCK('" . $connection->real_escape_string( $name ) . "', 1)" );
		$row        = $got ? $got->fetch_row() : null;
		if ( ! is_array( $row ) || '1' !== (string) ( $row[0] ?? '' ) ) {
			$connection->close();
			$this->fail( 'The second database connection could not hold the proposal lock.' );
		}
		return static function () use ( $connection, $name ): void {
			$connection->query( "SELECT RELEASE_LOCK('" . $connection->real_escape_string( $name ) . "')" );
			$connection->close();
		};
	}

	/**
	 * @return array{acquired: bool, release: callable(): void}
	 */
	private function tryLock( int $post_id ): array {
		$connection = $this->secondConnection();
		$name       = 'seostudio_proposal_' . $post_id;
		$got        = $connection->query( "SELECT GET_LOCK('" . $connection->real_escape_string( $name ) . "', 0)" );
		$row        = $got ? $got->fetch_row() : null;
		$acquired   = is_array( $row ) && '1' === (string) ( $row[0] ?? '' );
		return array(
			'acquired' => $acquired,
			'release'  => static function () use ( $connection, $name ): void {
				$connection->query( "SELECT RELEASE_LOCK('" . $connection->real_escape_string( $name ) . "')" );
				$connection->close();
			},
		);
	}

	private function secondConnection(): \mysqli {
		$connection = new \mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
		if ( $connection->connect_error ) {
			$this->fail( 'A second database connection is required to prove the proposal lock.' );
		}
		return $connection;
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function coreUpdate( int $post_id, array $body, ?string $revision ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( null !== $revision ) {
			$request->set_header( 'X-Zoop-Proposal-Revision', $revision );
		}
		$request->set_body( (string) wp_json_encode( $body ) );
		$response = rest_do_request( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		return $response;
	}

	private function seoUpdate( int $post_id, string $title, string $description ): \WP_REST_Response {
		$request = new WP_REST_Request( 'PUT', '/seostudio/v1/posts/' . $post_id . '/seo' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'title'       => $title,
					'description' => $description,
				)
			)
		);
		$response = rest_do_request( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		return $response;
	}

	private function classicFields( string $title, string $description ): void {
		$_POST = array(
			'seostudio_metabox_nonce' => wp_create_nonce( 'seostudio_metabox' ),
			'_seostudio_title'        => $title,
			'_seostudio_description'  => $description,
		);
	}

	/**
	 * @param array<string, mixed> $body
	 */
	private function request( string $method, int $post_id, string $path, array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( $method, '/seostudio/v1/posts/' . $post_id . $path );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		$response = rest_do_request( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		return $response;
	}

	private function liveHead( int $post_id ): string {
		$link = get_permalink( $post_id );
		if ( is_string( $link ) && '' !== $link ) {
			$this->go_to( $link );
		}
		ob_start();
		if ( function_exists( '_wp_render_title_tag' ) ) {
			_wp_render_title_tag();
		}
		( new \SEOStudio\Output\HeadOutput() )->render();
		$robots = apply_filters( 'wp_robots', array() );
		if ( is_array( $robots ) && ! empty( $robots['nofollow'] ) ) {
			echo '<meta name="robots" content="nofollow" />';
		}
		return (string) ob_get_clean();
	}

	private function observe( int $post_id, string $html, string $proposal_title, string $proposal_description, string $baseline_title, string $baseline_description, bool $restore = false ): \WP_REST_Response {
		$home = static function ( string $url, string $path ): string {
			unset( $url );
			$suffix = '' === $path ? '/' : '/' . ltrim( $path, '/' );
			return 'https://example.test' . $suffix;
		};
		$link = static function () use ( $post_id ): string {
			return 'https://example.test/?p=' . $post_id;
		};
		add_filter( 'home_url', $home, 10, 2 );
		add_filter( 'post_link', $link, 10, 0 );
		add_filter( 'page_link', $link, 10, 0 );
		add_filter( 'post_type_link', $link, 10, 0 );
		try {
			return $this->observedRequest( $post_id, $html, $proposal_title, $proposal_description, $baseline_title, $baseline_description, $restore );
		} finally {
			remove_filter( 'home_url', $home, 10 );
			remove_filter( 'post_link', $link, 10 );
			remove_filter( 'page_link', $link, 10 );
			remove_filter( 'post_type_link', $link, 10 );
		}
	}

	private function observedRequest( int $post_id, string $html, string $proposal_title, string $proposal_description, string $baseline_title, string $baseline_description, bool $restore ): \WP_REST_Response {
		$store  = new ProposalStore();
		$stored = $store->commit( $post_id, $proposal_title, $proposal_description, $store->revision( $post_id ) );
		$this->assertTrue( $stored['ok'] );
		$controller = new AppearanceController(
			new Decision(),
			new Renderer(),
			new LoopbackFetcher(
				new FetchPolicy( static fn (): array => array( '93.184.216.34' ) ),
				new Extractor(),
				static fn (): array => array(
					'code'     => 200,
					'location' => '',
					'body'     => $html,
					'robots'   => '',
				)
			),
			new HeadOutput()
		);
		$path    = $restore ? '/appearance/restore' : '/appearance/apply';
		$request = new WP_REST_Request( 'POST', '/seostudio/v1/posts/' . $post_id . $path );
		$request->set_param( 'post_id', $post_id );
		$request->set_header( 'Content-Type', 'application/json' );
		$body = array(
			'confirm'              => true,
			'baseline_title'       => $baseline_title,
			'baseline_description' => $baseline_description,
		);
		if ( ! $restore ) {
			$body['proposal_revision'] = $stored['revision'];
		}
		$request->set_body( (string) wp_json_encode( $body ) );
		$response = $restore ? $controller->restore( $request ) : $controller->apply( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		return $response;
	}
}
