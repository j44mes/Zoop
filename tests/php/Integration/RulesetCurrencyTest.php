<?php
/**
 * On-page ruleset currency is independent of plugin version and evidence version.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Content\FetchPolicy;
use SEOStudio\Content\LoopbackFetcher;
use SEOStudio\Content\PageModel;
use SEOStudio\Content\SourceFingerprint;
use SEOStudio\Core\Plugin;
use SEOStudio\Core\RulesetApplier;
use SEOStudio\Core\Settings;
use SEOStudio\Core\Upgrader;
use SEOStudio\Core\VersionIdentity;
use SEOStudio\Rest\PagesController;
use SEOStudio\Scoring\OnPage\Ruleset;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Storage\Tables;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Unstamped scores are retired. A later ruleset does not need a new schema.
 */
final class RulesetCurrencyTest extends WP_UnitTestCase {
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

	public function test_schema_seven_retires_unstamped_evidence_version_two_scores(): void {
		$settings = Settings::all();
		$settings['schema_version'] = 6;
		update_option( Settings::OPTION, $settings, true );
		$planted = $this->plantUnstampedCurrentScore();
		delete_option( Ruleset::OPTION );

		Upgrader::maybeUpgrade();

		$this->assertSame( 7, (int) get_option( Settings::OPTION )['schema_version'] );
		$this->assertSame( Ruleset::CURRENT, (int) get_option( Ruleset::OPTION ) );
		$this->assertRetired( $planted );
		$this->assertSame( $planted['model'], ( new PagesRepository() )->find( $planted['page_id'] )['page_model'] );
		$this->assertSame( '1', (string) ( new PagesRepository() )->find( $planted['page_id'] )['has_published_snapshot'] );
		$this->assertSame( 1, (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM ' . Tables::scores() . ' WHERE id = %d', $planted['score_id'] ) ) );

		$list = rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/pages' ) )->get_data();
		$item = null;
		foreach ( $list['items'] as $row ) {
			if ( (int) $row['page_id'] === $planted['page_id'] ) {
				$item = $row;
			}
		}
		$this->assertIsArray( $item );
		$this->assertFalse( $item['presentation']['definitive'] );
		$this->assertSame( 'Out of date', $item['presentation']['headline'] );
		$this->assertNull( $item['onpage']['score'] );
		$this->assertSame( 'Scores need checking against the current rules.', $item['presentation']['missing'][0]['label'] );
	}

	public function test_a_current_schema_still_retries_an_unapplied_ruleset(): void {
		$planted = $this->plantUnstampedCurrentScore();
		$settings = Settings::all();
		$settings['schema_version'] = Upgrader::CURRENT;
		update_option( Settings::OPTION, $settings, true );
		delete_option( Ruleset::OPTION );

		$this->assertTrue( RulesetApplier::synchronise() );

		$this->assertSame( Upgrader::CURRENT, (int) get_option( Settings::OPTION )['schema_version'] );
		$this->assertSame( Ruleset::CURRENT, (int) get_option( Ruleset::OPTION ) );
		$this->assertRetired( $planted );
	}

	public function test_a_later_ruleset_runs_without_a_schema_change(): void {
		$post = $this->post( 'Rank tracker title' );
		$this->live( $post, $this->html( 'Rank tracker title', 'Rank tracker title' ) );
		$row = $this->row( $post );
		$this->assertSame( '1', (string) $row['evidence_ready'] );
		$before = (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Tables::scores() );
		$schema = (int) get_option( Settings::OPTION )['schema_version'];

		$this->assertTrue( RulesetApplier::synchronise( Ruleset::CURRENT + 1 ) );
		$this->assertTrue( RulesetApplier::synchronise( Ruleset::CURRENT + 1 ) );

		$after = $this->row( $post );
		$this->assertSame( $schema, (int) get_option( Settings::OPTION )['schema_version'] );
		$this->assertSame( Ruleset::CURRENT + 1, (int) get_option( Ruleset::OPTION ) );
		$this->assertSame( 'needs_reanalysis', $after['onpage_state'] );
		$this->assertSame( '0', (string) $after['evidence_ready'] );
		$this->assertSame( 'ruleset', $after['missing_evidence'] );
		$this->assertNull( $after['onpage_score'] );
		$this->assertSame( $before, (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . Tables::scores() ) );
		$this->assertSame( $row['page_model'], $after['page_model'] );
	}

	public function test_a_failed_retirement_does_not_advance_the_marker(): void {
		$post = $this->post( 'Rank tracker title' );
		$this->live( $post, $this->html( 'Rank tracker title', 'Rank tracker title' ) );
		$block = static function ( $query ) {
			if ( is_string( $query ) && str_contains( $query, "missing_evidence = 'ruleset'" ) ) {
				return '';
			}
			return $query;
		};
		add_filter( 'query', $block );
		try {
			$applied = RulesetApplier::synchronise( Ruleset::CURRENT + 1 );
		} finally {
			remove_filter( 'query', $block );
		}

		$this->assertFalse( $applied );
		$this->assertSame( Ruleset::CURRENT, (int) get_option( Ruleset::OPTION ) );
		$row = $this->row( $post );
		$this->assertSame( 'current', $row['onpage_state'] );
		$this->assertSame( '1', (string) $row['evidence_ready'] );
		$this->assertNotNull( $row['onpage_score'] );
	}

	public function test_plugin_version_alone_does_not_retire_a_compatible_score(): void {
		$post = $this->post( 'Rank tracker title' );
		$this->live( $post, $this->html( 'Rank tracker title', 'Rank tracker title' ) );
		$score_id = (int) $this->row( $post )['onpage_score_id'];
		$GLOBALS['wpdb']->update(
			Tables::scores(),
			array( 'plugin_version' => '0.5.0' ),
			array( 'id' => $score_id )
		);

		$this->assertTrue( RulesetApplier::synchronise() );

		$row = $this->row( $post );
		$this->assertSame( 'current', $row['onpage_state'] );
		$this->assertSame( '1', (string) $row['evidence_ready'] );
		$this->assertSame( '0.5.0', (string) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT plugin_version FROM ' . Tables::scores() . ' WHERE id = %d', $score_id ) ) );
		$saved = Settings::sanitize( array_merge( Settings::all(), array( 'home_title' => 'Kept' ) ) );
		update_option( Settings::OPTION, $saved, true );
		$this->assertSame( Ruleset::CURRENT, (int) get_option( Ruleset::OPTION ) );
	}

	public function test_restoring_an_old_keyphrase_does_not_revive_an_incompatible_score(): void {
		$planted = $this->plantUnstampedCurrentScore();
		delete_option( Ruleset::OPTION );
		$this->assertTrue( RulesetApplier::synchronise() );
		$this->assertRetired( $planted );

		update_post_meta( $planted['post_id'], '_seostudio_focus_keyphrase', 'other phrase' );
		update_post_meta( $planted['post_id'], '_seostudio_focus_keyphrase', 'rank tracker' );
		( new PagesRepository() )->syncFocus( $planted['post_id'] );

		$this->assertRetired( $planted );
		$record = $this->record( $planted['post_id'] );
		$this->assertFalse( $record['presentation']['definitive'] );
		foreach ( $record['scores'] as $score ) {
			if ( (int) $score['id'] === $planted['score_id'] ) {
				$this->assertFalse( $score['current'] );
			}
		}
	}

	public function test_content_analysis_cannot_restore_readiness_and_a_live_check_can(): void {
		$planted = $this->plantUnstampedCurrentScore();
		delete_option( Ruleset::OPTION );
		RulesetApplier::synchronise();
		$history = (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM ' . Tables::scores() . ' WHERE page_id = %d', $planted['page_id'] ) );

		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'object_type', 'post' );
		$request->set_param( 'object_id', $planted['post_id'] );
		$request->set_param( 'mode', 'content' );
		$request->set_param( 'keyphrase', 'rank tracker' );
		$content = ( new PagesController() )->analyseObject( $request );
		$this->assertSame( 200, $content->get_status() );
		$row = ( new PagesRepository() )->find( $planted['page_id'] );
		$this->assertIsArray( $row );
		$this->assertSame( '0', (string) $row['evidence_ready'] );
		$this->assertFalse( $content->get_data()['presentation']['definitive'] );
		$this->assertGreaterThan( $history, (int) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT COUNT(*) FROM ' . Tables::scores() . ' WHERE page_id = %d', $planted['page_id'] ) ) );

		$live = $this->live( $planted['post_id'], $this->html( 'Rank tracker title', 'Rank tracker title' ) );
		$this->assertTrue( $live['presentation']['definitive'] );
		$this->assertSame( '1', (string) $this->row( $planted['post_id'] )['evidence_ready'] );
		$this->assertSame( Ruleset::CURRENT, (int) $live['scores'][0]['details']['ruleset_version'] );
	}

	public function test_version_label_is_supplied_by_php(): void {
		$caps     = rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/capabilities' ) )->get_data();
		$settings = rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/settings' ) )->get_data();
		$label    = VersionIdentity::label();

		$this->assertSame( $label, $caps['version_label'] );
		$this->assertSame( $label, $settings['version_label'] );
		$this->assertStringContainsString( SEOSTUDIO_VERSION, $label );
		$this->assertStringStartsWith( 'Zoop ', $label );
		if ( defined( 'SEOSTUDIO_BUILD' ) && 1 === preg_match( '/\A[0-9a-f]{7}\z/', (string) SEOSTUDIO_BUILD ) ) {
			$this->assertStringContainsString( '(build ' . SEOSTUDIO_BUILD . ')', $label );
		} else {
			$this->assertStringNotContainsString( '(build ', $label );
		}
	}

	/**
	 * @return array{post_id: int, page_id: int, score_id: int, model: string}
	 */
	private function plantUnstampedCurrentScore(): array {
		$post_id = $this->post( 'Rank tracker title' );
		$pages   = new PagesRepository();
		$model   = new PageModel(
			url: 'https://example.test/?p=' . $post_id,
			titleTag: 'Rank tracker title',
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
		$source = SourceFingerprint::current( $post_id );
		$details = array(
			'earned'           => 40,
			'denominator'      => 80,
			'evidence_version' => 2,
			'input_hash'       => (string) $stored['input_hash'],
			'source_hash'      => $source,
			'readiness'        => array(
				'ready'             => true,
				'missing'           => '',
				'checks_evaluated'  => 10,
				'checks_applicable' => 12,
				'index_status'      => 'indexable',
				'index_source'      => 'meta',
			),
			'assessments'      => array(
				array(
					'id'     => 'title.exists',
					'status' => 'fail',
				),
			),
		);
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
			),
			array( 'id' => $page_id )
		);
		$fresh = $pages->find( $page_id );
		$this->assertIsArray( $fresh );

		return array(
			'post_id'  => $post_id,
			'page_id'  => $page_id,
			'score_id' => $score_id,
			'model'    => (string) $fresh['page_model'],
		);
	}

	/**
	 * @param array{post_id: int, page_id: int, score_id: int, model: string} $planted
	 */
	private function assertRetired( array $planted ): void {
		$row = ( new PagesRepository() )->find( $planted['page_id'] );
		$this->assertIsArray( $row );
		$this->assertSame( 'needs_reanalysis', $row['onpage_state'] );
		$this->assertSame( '0', (string) $row['evidence_ready'] );
		$this->assertSame( 'ruleset', $row['missing_evidence'] );
		$this->assertNull( $row['onpage_score'] );
		$this->assertNull( $row['onpage_score_id'] );
		$this->assertStringContainsString( 'Kept', (string) $row['page_model'] );
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

	/**
	 * @return array<string, mixed>
	 */
	private function record( int $postId ): array {
		$row = $this->row( $postId );
		$request = new WP_REST_Request( 'GET' );
		$request->set_param( 'page_id', (int) $row['id'] );
		$result = ( new PagesController() )->show( $request );
		$this->assertNotInstanceOf( \WP_Error::class, $result );

		return $result->get_data();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row( int $postId ): array {
		$row = ( new PagesRepository() )->findByObject( 'post', $postId );
		$this->assertIsArray( $row );

		return $row;
	}

	private function html( string $title, string $heading ): string {
		return '<html><head><title>' . esc_html( $title ) . '</title></head><body><header><h1>' . esc_html( $heading ) . '</h1></header><main><p>Body copy for the page.</p></main></body></html>';
	}
}
