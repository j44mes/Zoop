<?php
/**
 * Cached on-page scores stay current only for the matching evidence snapshot.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Content\PageModel;
use SEOStudio\Core\Plugin;
use SEOStudio\Core\Settings;
use SEOStudio\Core\Upgrader;
use SEOStudio\Jobs\FetchLive;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Storage\ScoresRepository;
use SEOStudio\Storage\Tables;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Schema 5 states, filters, and reanalysis.
 */
final class ScoreFreshnessTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_four_states_survive_upgrade_filters_focus_and_reanalysis(): void {
		$stale   = $this->post( 'Stale page' );
		$plain   = $this->post( 'Rendered page' );
		$failed  = $this->post( 'Failed page' );
		$pages   = new PagesRepository();
		$stale_id = $pages->storeModel( $stale, new PageModel( url: 'https://example.test/stale', titleTag: 'Stale', bodyText: 'rank tracker' ), 'snapshot-a' );
		$plain_id = $pages->storeModel( $plain, new PageModel( url: 'https://example.test/plain', bodyText: 'rendered' ), 'snapshot-b' );
		$pages->recordError( $failed, 'Fetch blocked.' );

		update_post_meta( $stale, '_seostudio_focus_keyphrase', 'rank tracker' );
		update_post_meta( $plain, '_seostudio_focus_keyphrase', 'rank tracker' );
		$pages->syncFocus( $stale );
		$score_id = $this->insertLegacyScore( $stale_id );
		$this->denormalise( $stale_id, $score_id );

		$settings                    = Settings::all();
		$settings['schema_version']  = 4;
		update_option( Settings::OPTION, $settings, true );
		Upgrader::maybeUpgrade();

		$stale_row = $pages->find( $stale_id );
		$plain_row = $pages->find( $plain_id );
		$failed_row = $pages->findByObject( 'post', $failed );
		$this->assertIsArray( $stale_row );
		$this->assertIsArray( $plain_row );
		$this->assertIsArray( $failed_row );
		$this->assertSame( 'needs_reanalysis', $stale_row['onpage_state'] );
		$this->assertNull( $stale_row['onpage_score'] );
		$this->assertSame( 'unscored', $plain_row['onpage_state'] );
		$this->assertSame( 'not_analysed', $failed_row['onpage_state'] );
		$this->assertSame( '35', (string) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT score FROM ' . Tables::scores() . ' WHERE id = %d', $score_id ) ) );

		$pages->syncFocus( $stale );
		$this->assertSame( 'needs_reanalysis', $pages->find( $stale_id )['onpage_state'] );
		$this->assertNull( $pages->find( $stale_id )['onpage_score'] );

		$list = rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/pages' ) )->get_data();
		$this->assertSame( 0, $list['summary']['red'] );
		$this->assertNull( $list['summary']['average'] );
		$by_state = array();
		foreach ( $list['items'] as $item ) {
			$by_state[ $item['title'] ] = $item;
		}
		$this->assertSame( 'needs_reanalysis', $by_state['Stale page']['score_state'] );
		$this->assertNull( $by_state['Stale page']['onpage']['score'] );
		$this->assertSame( '', $by_state['Stale page']['top_issue'] );
		$this->assertSame( 'unscored', $by_state['Rendered page']['score_state'] );
		$this->assertSame( 'not_analysed', $by_state['Failed page']['score_state'] );

		$red = new WP_REST_Request( 'GET', '/seostudio/v1/pages' );
		$red->set_param( 'rating', 'red' );
		$this->assertSame( 0, rest_do_request( $red )->get_data()['total'] );

		$record = rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/pages/' . $stale_id ) )->get_data();
		$legacy = $this->onpage( $record['scores'], false );
		$this->assertSame( 35, $legacy['score'] );
		$this->assertFalse( $legacy['current'] );
		$this->assertLessThan( 2, $legacy['evidence_version'] );

		$analyse = new WP_REST_Request( 'POST', '/seostudio/v1/analyse/post/' . $stale );
		$analyse->set_header( 'Content-Type', 'application/json' );
		$analyse->set_body( (string) wp_json_encode( array( 'keyphrase' => 'rank tracker' ) ) );
		$scored = rest_do_request( $analyse );
		$this->assertSame( 200, $scored->get_status() );
		$this->assertSame( 'current', $pages->find( $stale_id )['onpage_state'] );
		$this->assertNotNull( $pages->find( $stale_id )['onpage_score'] );
		$this->assertSame( 'unscored', $pages->find( $plain_id )['onpage_state'] );
		$this->assertSame( 'not_analysed', $pages->findByObject( 'post', $failed )['onpage_state'] );
		$this->assertSame( '35', (string) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( 'SELECT score FROM ' . Tables::scores() . ' WHERE id = %d', $score_id ) ) );

		$fresh = rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/pages/' . $stale_id ) )->get_data();
		$current = $this->onpage( $fresh['scores'], true );
		$this->assertTrue( $current['current'] );
		$this->assertSame( 2, $current['evidence_version'] );
		$this->assertSame( (int) $pages->find( $stale_id )['onpage_score_id'], $current['id'] );

		( new ScoresRepository() )->insert( $stale_id, 'other phrase', ( new \SEOStudio\Scoring\OnPage\Scorer() )->score( $pages->loadModel( $stale ), 'other phrase' ), '0.5.0', 'snapshot-a' );
		$mixed = rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/pages/' . $stale_id ) )->get_data();
		foreach ( $mixed['scores'] as $row ) {
			if ( 'onpage' === $row['engine'] && 'other phrase' === $row['keyphrase'] ) {
				$this->assertSame( 2, $row['evidence_version'] );
				$this->assertFalse( $row['current'] );
			}
		}

		$pages->storeModel( $stale, new PageModel( url: 'https://example.test/stale', titleTag: 'Changed', bodyText: 'different snapshot' ), 'snapshot-c' );
		$this->assertSame( 'needs_reanalysis', $pages->find( $stale_id )['onpage_state'] );
		$this->assertNull( $pages->find( $stale_id )['onpage_score'] );
		$after = rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/pages' ) )->get_data();
		$this->assertSame( 0, $after['summary']['scored'] );

		update_post_meta( $plain, '_seostudio_focus_keyphrase', 'never scored' );
		$pages->syncFocus( $plain );
		$this->assertSame( 'unscored', $pages->find( $plain_id )['onpage_state'] );
	}

	public function test_a_blocked_fetch_does_not_replace_calculated_evidence(): void {
		$post = $this->post( 'Blocked fetch' );
		update_post_meta( $post, '_seostudio_focus_keyphrase', 'rank tracker' );
		update_post_meta( $post, '_seostudio_title', 'Rank tracker guide' );
		$settings         = Settings::all();
		$settings['mode'] = 'owner';
		update_option( Settings::OPTION, $settings, true );

		$analyse = new WP_REST_Request( 'POST', '/seostudio/v1/analyse/post/' . $post );
		$analyse->set_header( 'Content-Type', 'application/json' );
		$analyse->set_body( (string) wp_json_encode( array( 'keyphrase' => 'rank tracker' ) ) );
		$this->assertSame( 200, rest_do_request( $analyse )->get_status() );
		$before = ( new PagesRepository() )->loadModel( $post );
		$this->assertInstanceOf( PageModel::class, $before );
		$this->assertSame( 'calculated', $before->titleEvidence );

		$block = static function () {
			return new WP_Error( 'blocked', 'Blocked.' );
		};
		add_filter( 'seostudio_live_model', $block );
		try {
			( new FetchLive() )->handle( array( $post ), false );
		} finally {
			remove_filter( 'seostudio_live_model', $block );
		}

		$after = ( new PagesRepository() )->loadModel( $post );
		$this->assertInstanceOf( PageModel::class, $after );
		$this->assertSame( 'calculated', $after->titleEvidence );
		$this->assertNotSame( 'observed_absent', $after->descriptionEvidence );
		$this->assertSame( 'current', ( new PagesRepository() )->findByObject( 'post', $post )['onpage_state'] );
	}

	private function post( string $title ): int {
		return self::factory()->post->create(
			array(
				'post_title'   => $title,
				'post_status'  => 'publish',
				'post_content' => '<p>rank tracker in the body.</p>',
			)
		);
	}

	private function insertLegacyScore( int $pageId ): int {
		global $wpdb;

		$wpdb->insert(
			Tables::scores(),
			array(
				'page_id'        => $pageId,
				'keyphrase'      => 'rank tracker',
				'keyphrase_hash' => md5( 'rank tracker' ),
				'engine'         => 'onpage',
				'score'          => 35,
				'denominator'    => 100,
				'rating'         => 'red',
				'details'        => '{"earned":35,"denominator":100,"assessments":[{"id":"title.exists","status":"fail"}]}',
				'plugin_version' => '0.5.0',
				'created_at'     => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	private function denormalise( int $pageId, int $scoreId ): void {
		global $wpdb;

		$wpdb->update(
			Tables::pages(),
			array(
				'onpage_score_id'    => $scoreId,
				'onpage_score'       => 35,
				'onpage_denominator' => 100,
				'onpage_rating'      => 'red',
				'onpage_state'       => 'current',
			),
			array( 'id' => $pageId )
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $scores
	 * @return array<string, mixed>
	 */
	private function onpage( array $scores, bool $current ): array {
		foreach ( $scores as $score ) {
			if ( 'onpage' === $score['engine'] && $current === $score['current'] ) {
				return $score;
			}
		}

		$this->fail( 'Missing on-page score.' );
	}
}
