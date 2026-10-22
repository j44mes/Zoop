<?php
/**
 * Ruleset-retirement notice, review list, and dismissal.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Content\FetchPolicy;
use SEOStudio\Content\LoopbackFetcher;
use SEOStudio\Content\PageModel;
use SEOStudio\Content\SourceFingerprint;
use SEOStudio\Core\LegacyScoreNotice;
use SEOStudio\Core\Plugin;
use SEOStudio\Core\RulesetApplier;
use SEOStudio\Core\Settings;
use SEOStudio\Core\Uninstaller;
use SEOStudio\Core\UpgradeNotice;
use SEOStudio\Core\Upgrader;
use SEOStudio\Integrations\Detector;
use SEOStudio\Output\HeadOutput;
use SEOStudio\Rest\PagesController;
use SEOStudio\Scoring\OnPage\Ruleset;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Storage\PublishedFreshness;
use SEOStudio\Storage\Tables;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The banner counts the same pages the review filter lists.
 */
final class UpgradeNoticeTest extends WP_UnitTestCase {
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

	public function tear_down(): void {
		Detector::clearForce();
		parent::tear_down();
	}

	public function test_a_fresh_install_and_a_matching_ruleset_do_not_show_a_notice(): void {
		$this->assertNull( $this->notice() );
		$this->assertNull( $this->legacyNotice() );
		$this->assertFalse( get_option( UpgradeNotice::OPTION ) );
		$this->assertFalse( get_option( LegacyScoreNotice::OPTION ) );

		$planted = $this->plantDefinitive( get_current_user_id(), 'Already current', Ruleset::CURRENT );
		delete_option( Ruleset::OPTION );
		delete_option( UpgradeNotice::OPTION );

		$this->assertTrue( RulesetApplier::synchronise() );
		$this->assertNull( $this->notice() );
		$this->assertFalse( get_option( UpgradeNotice::OPTION ) );
		$this->assertSame( 'current', ( new PagesRepository() )->find( $planted['page_id'] )['onpage_state'] );
		$this->assertSame( '1', (string) ( new PagesRepository() )->find( $planted['page_id'] )['evidence_ready'] );
	}

	public function test_a_pre_evidence_upgrade_explains_saved_scores_without_calling_them_verified(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$other  = self::factory()->user->create( array( 'role' => 'author' ) );
		$mine   = $this->plantHistorical( $author, 'Saved author page' );
		$secret = $this->plantHistorical( $other, 'Saved other page' );
		$plain  = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_author' => $author,
				'post_title'  => 'Never scored',
			)
		);
		( new PagesRepository() )->storeModel(
			$plain,
			new PageModel(
				url: 'https://example.test/?p=' . $plain,
				titleTag: 'Never scored',
				renderMode: 'snapshot'
			),
			'model-hash'
		);
		$history = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Tables::scores() );
		$settings = Settings::all();
		$settings['schema_version'] = 3;
		remove_all_actions( 'update_option_' . Settings::OPTION );
		update_option( Settings::OPTION, $settings, true );
		add_action( 'update_option_' . Settings::OPTION, array( PublishedFreshness::class, 'siteChanged' ) );
		delete_option( Ruleset::OPTION );
		delete_option( UpgradeNotice::OPTION );
		delete_option( LegacyScoreNotice::OPTION );

		Upgrader::maybeUpgrade();

		$this->assertSame( Upgrader::CURRENT, (int) get_option( Settings::OPTION )['schema_version'] );
		$this->assertSame( Ruleset::CURRENT, (int) get_option( Ruleset::OPTION ) );
		$this->assertSame( $history, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Tables::scores() ) );
		$this->assertSame( 'needs_reanalysis', ( new PagesRepository() )->find( $mine['page_id'] )['onpage_state'] );
		$this->assertSame( '0', (string) ( new PagesRepository() )->find( $mine['page_id'] )['evidence_ready'] );
		$this->assertNull( $this->notice() );

		$admin = $this->legacyNotice();
		$this->assertIsArray( $admin );
		$this->assertSame( 2, $admin['count'] );
		$this->assertSame( "Zoop's page checks have been updated. Your previous scores have been kept but are no longer shown. 2 pages need a new published-page check.", $admin['message'] );
		$this->assertStringContainsString( 'score_state=legacy_scores', $admin['review_url'] );
		$this->assertStringNotContainsString( 'ruleset_retired', $admin['review_url'] );
		$this->assertStringNotContainsString( 'updated scoring rules', $admin['message'] );
		$list = $this->pages( 'legacy_scores' );
		$this->assertSame( $admin['count'], $list['total'] );
		$this->assertSame( 'Check published page', $list['items'][0]['recovery_label'] );
		$titles = array_column( $list['items'], 'title' );
		$this->assertNotContains( 'Never scored', $titles );

		wp_set_current_user( $author );
		$author_notice = $this->legacyNotice();
		$this->assertIsArray( $author_notice );
		$this->assertSame( 1, $author_notice['count'] );
		$this->assertSame( "Zoop's page checks have been updated. Your previous scores have been kept but are no longer shown. 1 page needs a new published-page check.", $author_notice['message'] );
		$this->assertSame( 1, $this->pages( 'legacy_scores' )['total'] );
		$this->assertSame( 'Saved author page', $this->pages( 'legacy_scores' )['items'][0]['title'] );

		$ruleset = new WP_REST_Request( 'POST', '/seostudio/v1/notices/ruleset-retirement/dismiss' );
		$this->assertSame( 200, rest_do_request( $ruleset )->get_status() );
		$this->assertSame( 1, $this->legacyNotice()['count'] );
		$dismiss = new WP_REST_Request( 'POST', '/seostudio/v1/notices/legacy-scores/dismiss' );
		$this->assertSame( 200, rest_do_request( $dismiss )->get_status() );
		$this->assertNull( $this->legacyNotice() );

		wp_set_current_user( $other );
		$this->assertSame( 1, $this->legacyNotice()['count'] );
		$this->assertSame( 'Saved other page', $this->pages( 'legacy_scores' )['items'][0]['title'] );
		$GLOBALS['wpdb']->update(
			Tables::pages(),
			array(
				'onpage_state'   => 'current',
				'evidence_ready' => 1,
			),
			array( 'id' => $secret['page_id'] )
		);
		$this->assertNull( $this->legacyNotice() );
		$this->assertSame( 0, $this->pages( 'legacy_scores' )['total'] );
		$this->assertSame( 1, (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM ' . Tables::scores() . ' WHERE page_id = %d', $secret['page_id'] ) ) );
	}

	public function test_notice_count_list_permissions_and_dismissal_follow_rechecks(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$other  = self::factory()->user->create( array( 'role' => 'author' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$mine   = $this->plantDefinitive( $author, 'Author visible page' );
		$secret = $this->plantDefinitive( $other, 'Secret other title' );
		$stale  = $this->plantDefinitive( $other, 'Already stale page' );
		$this->demote( $stale['page_id'] );
		$history = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Tables::scores() );
		delete_option( Ruleset::OPTION );
		delete_option( UpgradeNotice::OPTION );
		delete_option( UpgradeNotice::PENDING );

		$this->assertTrue( RulesetApplier::synchronise() );

		$this->assertSame( Ruleset::CURRENT, (int) get_option( Ruleset::OPTION ) );
		$this->assertSame( Upgrader::CURRENT, (int) get_option( Settings::OPTION )['schema_version'] );
		$this->assertSame( $history, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Tables::scores() ) );
		$this->assertSame( 1, (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM ' . Tables::scores() . ' WHERE id = %d', $mine['score_id'] ) ) );
		$this->assertSame( 'ruleset', ( new PagesRepository() )->find( $mine['page_id'] )['missing_evidence'] );
		$this->assertSame( 'needs_reanalysis', ( new PagesRepository() )->find( $stale['page_id'] )['onpage_state'] );
		$this->assertSame( '', ( new PagesRepository() )->find( $stale['page_id'] )['missing_evidence'] );

		wp_set_current_user( get_current_user_id() );
		$admin_id = get_current_user_id();
		$notice   = $this->notice();
		$this->assertIsArray( $notice );
		$this->assertSame( 2, $notice['count'] );
		$this->assertSame( 'Zoop 0.6 uses updated scoring rules, so 2 pages need checking again. Your previous scores have been kept but are no longer shown.', $notice['message'] );
		$this->assertStringContainsString( 'score_state=ruleset_retired', $notice['review_url'] );
		$this->assertStringNotContainsString( 'needs_reanalysis', $notice['review_url'] );
		$this->assertStringNotContainsString( 'Author visible page', $notice['message'] );
		$this->assertStringNotContainsString( 'Secret other title', $notice['message'] );
		$retired = $this->pages( 'ruleset_retired' );
		$this->assertSame( $notice['count'], $retired['total'] );
		$this->assertSame( 2, $retired['total'] );
		$outdated = $this->pages( 'needs_reanalysis' );
		$this->assertSame( 3, $outdated['total'] );
		$this->assertNotSame( $retired['total'], $outdated['total'] );

		$first  = $this->pages( 'ruleset_retired', 1, 1 );
		$second = $this->pages( 'ruleset_retired', 1, 2 );
		$this->assertSame( 2, $first['total'] );
		$this->assertCount( 1, $first['items'] );
		$this->assertCount( 1, $second['items'] );
		$this->assertNotSame( $first['items'][0]['page_id'], $second['items'][0]['page_id'] );

		wp_set_current_user( $author );
		$author_notice = $this->notice();
		$this->assertIsArray( $author_notice );
		$this->assertSame( 1, $author_notice['count'] );
		$this->assertSame( 'Zoop 0.6 uses updated scoring rules, so 1 page needs checking again. Your previous scores have been kept but are no longer shown.', $author_notice['message'] );
		$author_list = $this->pages( 'ruleset_retired' );
		$this->assertSame( 1, $author_list['total'] );
		$this->assertSame( 'Author visible page', $author_list['items'][0]['title'] );
		$encoded = (string) wp_json_encode( $author_list );
		$this->assertStringNotContainsString( 'Secret other title', $encoded );
		$this->assertStringNotContainsString( 'Already stale page', $encoded );

		wp_set_current_user( $editor );
		$this->assertSame( 2, $this->notice()['count'] );
		$this->assertSame( 2, $this->pages( 'ruleset_retired' )['total'] );

		wp_set_current_user( $author );
		$dismissed = rest_do_request( new WP_REST_Request( 'POST', '/seostudio/v1/notices/ruleset-retirement/dismiss' ) );
		$this->assertSame( 200, $dismissed->get_status() );
		$this->assertNull( $this->notice() );
		$this->assertSame( Ruleset::CURRENT, (int) get_option( Ruleset::OPTION ) );
		$this->assertSame( Upgrader::CURRENT, (int) get_option( Settings::OPTION )['schema_version'] );
		$this->assertSame( 1, $this->pages( 'ruleset_retired' )['total'] );

		wp_set_current_user( $admin_id );
		$this->assertSame( 2, $this->notice()['count'] );

		$content = $this->content( $mine['post_id'] );
		$this->assertFalse( $content['presentation']['definitive'] );
		$this->assertSame( 'Content checked. Needs a published-page check to score.', $content['presentation']['headline'] );
		$this->assertSame( '', $content['presentation']['content_rating'] );
		$this->assertSame( '0', (string) ( new PagesRepository() )->find( $mine['page_id'] )['evidence_ready'] );
		$after = $this->notice();
		$this->assertIsArray( $after );
		$this->assertSame( 1, $after['count'] );
		$this->assertSame( 1, $this->pages( 'ruleset_retired' )['total'] );
		$this->assertSame( 'Secret other title', $this->pages( 'ruleset_retired' )['items'][0]['title'] );
		$this->assertSame( 2, $this->pages( 'needs_reanalysis' )['total'] );

		wp_set_current_user( $author );
		$this->assertNull( $this->notice() );
		$this->assertSame( 0, $this->pages( 'ruleset_retired' )['total'] );

		wp_set_current_user( $admin_id );
		$live = $this->live( $secret['post_id'], $this->html( 'Rank tracker title', 'Rank tracker title' ) );
		$this->assertTrue( $live['presentation']['definitive'] );
		$this->assertNull( $this->notice() );
		$this->assertSame( 0, $this->pages( 'ruleset_retired' )['total'] );
		$this->assertSame( 1, $this->pages( 'needs_reanalysis' )['total'] );
	}

	public function test_schema_six_upgrade_keeps_history_yoast_metadata_and_shows_the_notice(): void {
		$post = $this->plantDefinitive( get_current_user_id(), 'Rank tracker title' );
		update_post_meta( $post['post_id'], '_yoast_wpseo_title', 'Yoast title' );
		update_post_meta( $post['post_id'], '_yoast_wpseo_metadesc', 'Yoast description' );
		update_post_meta( $post['post_id'], '_seostudio_title', 'Zoop title' );
		// Saving Zoop title meta withholds a definitive score. Restore it so the upgrade sees the 0.5.0-shaped row.
		$GLOBALS['wpdb']->update(
			Tables::pages(),
			array(
				'onpage_score_id'        => $post['score_id'],
				'onpage_score'           => 40,
				'onpage_denominator'     => 80,
				'onpage_rating'          => 'orange',
				'onpage_state'           => 'current',
				'evidence_ready'         => 1,
				'has_published_snapshot' => 1,
				'missing_evidence'       => '',
			),
			array( 'id' => $post['page_id'] )
		);
		$settings                   = Settings::all();
		$settings['schema_version'] = 6;
		$settings['mode']           = 'owner';
		// A settings write marks published scores stale. Suppress every copy of that hook while planting schema 6.
		remove_all_actions( 'update_option_' . Settings::OPTION );
		update_option( Settings::OPTION, $settings, true );
		add_action( 'update_option_' . Settings::OPTION, array( PublishedFreshness::class, 'siteChanged' ) );
		delete_option( Ruleset::OPTION );
		Detector::force(
			array(
				'slug'    => 'yoast',
				'name'    => 'Yoast SEO',
				'version' => '22.0',
			)
		);
		$history = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM ' . Tables::scores() . ' WHERE page_id = %d', $post['page_id'] ) );

		Upgrader::maybeUpgrade();

		$this->assertSame( Upgrader::CURRENT, (int) get_option( Settings::OPTION )['schema_version'] );
		$this->assertSame( Ruleset::CURRENT, (int) get_option( Ruleset::OPTION ) );
		$this->assertSame( 'Yoast title', get_post_meta( $post['post_id'], '_yoast_wpseo_title', true ) );
		$this->assertSame( 'Yoast description', get_post_meta( $post['post_id'], '_yoast_wpseo_metadesc', true ) );
		$this->assertSame( 'Zoop title', get_post_meta( $post['post_id'], '_seostudio_title', true ) );
		$this->assertFalse( ( new HeadOutput() )->publishing() );
		$this->assertSame( 'Yoast SEO', rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/capabilities' ) )->get_data()['detected_name'] );
		$this->assertSame( $history, (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM ' . Tables::scores() . ' WHERE page_id = %d', $post['page_id'] ) ) );
		$this->assertSame( 'needs_reanalysis', ( new PagesRepository() )->find( $post['page_id'] )['onpage_state'] );
		$notice = $this->notice();
		$this->assertIsArray( $notice );
		$this->assertSame( 1, $notice['count'] );
		$list = $this->pages( 'ruleset_retired' );
		$this->assertSame( 1, $list['total'] );
		$this->assertSame( 'check_published', $list['items'][0]['recovery_action'] );
		$this->assertSame( 'Check published page', $list['items'][0]['recovery_label'] );
		$this->assertSame( 'Out of date', $list['items'][0]['presentation']['headline'] );
		$this->assertFalse( $list['items'][0]['presentation']['definitive'] );

		$content = $this->content( $post['post_id'] );
		$this->assertFalse( $content['presentation']['definitive'] );
		$this->assertSame( '0', (string) ( new PagesRepository() )->find( $post['page_id'] )['evidence_ready'] );
	}

	public function test_a_failed_retirement_does_not_announce_until_it_completes(): void {
		$this->plantDefinitive( get_current_user_id(), 'Interrupted page' );
		delete_option( Ruleset::OPTION );
		$block = static function ( $query ) {
			if ( is_string( $query ) && str_contains( $query, "missing_evidence = 'ruleset'" ) ) {
				return '';
			}
			return $query;
		};
		add_filter( 'query', $block );
		try {
			$applied = RulesetApplier::synchronise();
		} finally {
			remove_filter( 'query', $block );
		}

		$this->assertFalse( $applied );
		$this->assertSame( 0, (int) get_option( Ruleset::OPTION, 0 ) );
		$this->assertFalse( get_option( UpgradeNotice::OPTION ) );
		$this->assertNotSame( array(), get_option( UpgradeNotice::PENDING ) );

		$this->assertTrue( RulesetApplier::synchronise() );
		$this->assertSame( Ruleset::CURRENT, (int) get_option( Ruleset::OPTION ) );
		$this->assertSame( 1, $this->notice()['count'] );
		$this->assertFalse( get_option( UpgradeNotice::PENDING ) );
	}

	public function test_content_only_scores_do_not_raise_the_notice(): void {
		$post = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Content only page',
			)
		);
		update_post_meta( $post, '_seostudio_focus_keyphrase', 'rank tracker' );
		$this->content( $post );
		delete_option( Ruleset::OPTION );

		$this->assertTrue( RulesetApplier::synchronise( Ruleset::CURRENT + 1 ) );

		$this->assertNull( $this->notice() );
		$this->assertFalse( get_option( UpgradeNotice::OPTION ) );
		$this->assertSame( 'needs_reanalysis', ( new PagesRepository() )->findByObject( 'post', $post )['onpage_state'] );
	}

	public function test_uninstall_removes_the_notice_without_removing_scores_unless_asked(): void {
		$this->plantDefinitive( get_current_user_id(), 'Kept history' );
		delete_option( Ruleset::OPTION );
		RulesetApplier::synchronise();
		$this->assertNotFalse( get_option( UpgradeNotice::OPTION ) );
		update_option( LegacyScoreNotice::OPTION, array( 'page_ids' => array( 1 ) ), false );
		$scores = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Tables::scores() );

		Uninstaller::removeOptionsAndJobs();

		$this->assertFalse( get_option( UpgradeNotice::OPTION ) );
		$this->assertFalse( get_option( UpgradeNotice::PENDING ) );
		$this->assertFalse( get_option( LegacyScoreNotice::OPTION ) );
		$this->assertSame( $scores, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Tables::scores() ) );
	}

	/**
	 * Store a current evidence-ready score for one author.
	 *
	 * @return array{post_id: int, page_id: int, score_id: int}
	 */
	private function plantDefinitive( int $author, string $title, ?int $ruleset = null ): array {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_author'  => $author,
				'post_title'   => $title,
				'post_content' => '<h1>Inside the article</h1><p>Body copy for the page.</p>',
			)
		);
		update_post_meta( $post_id, '_seostudio_focus_keyphrase', 'rank tracker' );
		$pages   = new PagesRepository();
		$model   = new PageModel(
			url: 'https://example.test/?p=' . $post_id,
			titleTag: $title,
			renderMode: 'live',
			links: array(
				array(
					'href'   => 'https://example.test/kept',
					'anchor' => 'Kept',
					'kind'   => 'contextual',
				),
			)
		);
		$page_id = $pages->storeModel( $post_id, $model, $model->inputHash() );
		$stored  = $pages->find( $page_id );
		$this->assertIsArray( $stored );
		$details = array(
			'earned'           => 40,
			'denominator'      => 80,
			'evidence_version' => 2,
			'input_hash'       => (string) $stored['input_hash'],
			'source_hash'      => SourceFingerprint::current( $post_id ),
			'readiness'        => array( 'ready' => true ),
			'assessments'      => array(
				array(
					'id'     => 'title.exists',
					'status' => 'fail',
				),
			),
		);
		if ( null !== $ruleset ) {
			$details['ruleset_version'] = $ruleset;
		}
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
				'details'        => wp_json_encode( $details ),
				'plugin_version' => '0.5.0',
				'created_at'     => current_time( 'mysql', true ),
			)
		);
		$score_id = (int) $wpdb->insert_id;
		$wpdb->update(
			Tables::pages(),
			array(
				'onpage_score_id'        => $score_id,
				'onpage_score'           => 40,
				'onpage_denominator'     => 80,
				'onpage_rating'          => 'orange',
				'onpage_state'           => 'current',
				'evidence_ready'         => 1,
				'has_published_snapshot' => 1,
				'missing_evidence'       => '',
				'focus_keyphrase'        => 'rank tracker',
				'focus_hash'             => md5( 'rank tracker' ),
			),
			array( 'id' => $page_id )
		);

		return array(
			'post_id'  => $post_id,
			'page_id'  => $page_id,
			'score_id' => $score_id,
		);
	}

	private function demote( int $pageId ): void {
		$GLOBALS['wpdb']->update(
			Tables::pages(),
			array(
				'onpage_state'     => 'needs_reanalysis',
				'evidence_ready'   => 0,
				'missing_evidence' => '',
			),
			array( 'id' => $pageId )
		);
	}

	/**
	 * Capability payload for the ruleset notice.
	 *
	 * @return array<string, mixed>|null
	 */
	private function notice(): ?array {
		return $this->capabilityNotice( 'upgrade_notice' );
	}

	/**
	 * Capability payload for saved scores from before evidence-ready checks.
	 *
	 * @return array<string, mixed>|null
	 */
	private function legacyNotice(): ?array {
		return $this->capabilityNotice( 'legacy_notice' );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function capabilityNotice( string $key ): ?array {
		$data   = rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/capabilities' ) )->get_data();
		$notice = $data[ $key ] ?? null;

		return is_array( $notice ) ? $notice : null;
	}

	/**
	 * Store a schema-3 style score that was never evidence-ready.
	 *
	 * @return array{post_id: int, page_id: int, score_id: int}
	 */
	private function plantHistorical( int $author, string $title ): array {
		$planted = $this->plantDefinitive( $author, $title );
		$GLOBALS['wpdb']->update(
			Tables::pages(),
			array(
				'onpage_state'           => 'current',
				'evidence_ready'         => 0,
				'has_published_snapshot' => 0,
				'missing_evidence'       => '',
			),
			array( 'id' => $planted['page_id'] )
		);

		return $planted;
	}

	/**
	 * Page list for one score-state filter.
	 *
	 * @return array<string, mixed>
	 */
	private function pages( string $state, int $perPage = 20, int $page = 1 ): array {
		$request = new WP_REST_Request( 'GET', '/seostudio/v1/pages' );
		$request->set_param( 'score_state', $state );
		$request->set_param( 'per_page', $perPage );
		$request->set_param( 'page', $page );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		return $response->get_data();
	}

	/**
	 * Run a content-only analysis.
	 *
	 * @return array<string, mixed>
	 */
	private function content( int $postId ): array {
		$request = new WP_REST_Request( 'POST', '/seostudio/v1/analyse/post/' . $postId );
		$request->set_param( 'mode', 'content' );
		$request->set_param( 'keyphrase', 'rank tracker' );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		return $response->get_data();
	}

	/**
	 * Run a published-page check against supplied HTML.
	 *
	 * @return array<string, mixed>
	 */
	private function live( int $postId, string $html ): array {
		$fetcher = new LoopbackFetcher(
			policy: new FetchPolicy( static fn (): array => array( '93.184.216.34' ) ),
			transport: static fn (): array => array(
				'code'     => 200,
				'location' => '',
				'body'     => $html,
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

	private function html( string $title, string $heading ): string {
		return '<html><head><title>' . esc_html( $title ) . '</title></head><body><header><h1>' . esc_html( $heading ) . '</h1></header><main><p>Body copy for the page.</p></main></body></html>';
	}
}
