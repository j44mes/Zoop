<?php
/**
 * Audit queue tests.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Content\PageModel;
use SEOStudio\Core\Plugin;
use SEOStudio\Core\Settings;
use SEOStudio\Jobs\AnalysePost;
use SEOStudio\Jobs\AuditBatch;
use SEOStudio\Jobs\FetchLive;
use SEOStudio\Jobs\Scheduler;
use SEOStudio\Storage\AuditRunsRepository;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Storage\ScoresRepository;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A batch stores a cursor and continues. The live fetch can be forced without a network call.
 */
final class PhaseThreeTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		do_action( 'init' );
		do_action( 'rest_api_init' );
	}

	public function test_an_audit_walks_the_cursor_across_batches(): void {
		$settings                   = Settings::all();
		$settings['batch_size']     = 10;
		$settings['schema_version'] = \SEOStudio\Core\Upgrader::CURRENT;
		update_option( Settings::OPTION, $settings, true );

		$ids = array();
		for ( $index = 0; $index < 12; $index++ ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_title'   => 'Audit page ' . $index,
					'post_content' => '<p>Body copy for the audit.</p>',
					'post_status'  => 'publish',
				)
			);
		}
		update_post_meta( $ids[0], '_seostudio_focus_keyphrase', 'audit page' );

		$run_id = ( new AuditBatch() )->start();
		$run    = ( new AuditRunsRepository() )->find( $run_id );
		$this->assertIsArray( $run );
		$this->assertSame( 'done', $run['status'] );
		$this->assertSame( 12, (int) $run['processed'] );
		$this->assertSame( (int) max( $ids ), (int) $run['cursor_id'] );
		$this->assertNotNull( ( new PagesRepository() )->findByObject( 'post', $ids[0] ) );
	}

	public function test_an_audit_finishes_three_hundred_posts(): void {
		remove_action( 'save_post', array( Scheduler::class, 'onSave' ), 20 );

		$settings                   = Settings::all();
		$settings['batch_size']     = 25;
		$settings['schema_version'] = \SEOStudio\Core\Upgrader::CURRENT;
		update_option( Settings::OPTION, $settings, true );

		for ( $index = 0; $index < 300; $index++ ) {
			self::factory()->post->create(
				array(
					'post_title'   => 'Bulk audit ' . $index,
					'post_content' => '<p>Body copy for the bulk audit.</p>',
					'post_status'  => 'publish',
				)
			);
		}

		$expected = ( new AuditBatch() )->countPosts();
		$this->assertGreaterThanOrEqual( 300, $expected );

		$run_id = ( new AuditBatch() )->start();
		$run    = ( new AuditRunsRepository() )->find( $run_id );
		$this->assertIsArray( $run );
		$this->assertSame( 'done', $run['status'] );
		$this->assertSame( $expected, (int) $run['total'] );
		$this->assertSame( $expected, (int) $run['processed'] );
		$this->assertSame( 0, (int) $run['failed'] );
	}

	public function test_a_second_audit_is_rejected_while_one_is_queued(): void {
		global $wpdb;

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$wpdb->insert(
			$wpdb->prefix . 'seostudio_audit_runs',
			array(
				'type'       => 'audit',
				'status'     => 'running',
				'total'      => 1,
				'processed'  => 0,
				'failed'     => 0,
				'cursor_id'  => 0,
				'started_at' => current_time( 'mysql', true ),
				'report'     => '[]',
			)
		);

		$response = rest_do_request( new WP_REST_Request( 'POST', '/seostudio/v1/audits' ) );
		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'seostudio_conflict', $response->get_data()['code'] );
	}

	public function test_live_fetch_stores_a_forced_model(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Fetched later.</p>',
			)
		);
		add_filter(
			'seostudio_live_model',
			static function () {
				return new PageModel(
					url: 'https://example.test/forced',
					titleTag: 'Forced',
					renderMode: 'live',
					metadataStatus: 'observed_html'
				);
			}
		);

		( new FetchLive() )->handle( array( $post_id ) );
		$row = ( new PagesRepository() )->findByObject( 'post', $post_id );
		$this->assertIsArray( $row );
		$this->assertNotEmpty( $row['last_fetched_at'] );
		$this->assertNull( $row['last_error'] );
	}

	public function test_a_live_fetch_scores_and_content_analysis_keeps_measurements(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'Rank tracker',
				'post_content' => '<p>Content sentence about rank tracker.</p>',
			)
		);
		update_post_meta( $post_id, '_seostudio_focus_keyphrase', 'rank tracker' );
		add_filter(
			'seostudio_live_model',
			static function () {
				return new PageModel(
					url: 'https://example.test/live',
					titleTag: 'Rank tracker',
					bodyText: 'Live sentence about rank tracker.',
					wordCount: 5,
					renderMode: 'live',
					hasExternalCss: true,
					htmlBytes: 1200,
					visibleTextBytes: 300,
					metadataStatus: 'observed_html'
				);
			}
		);

		try {
			( new FetchLive() )->handle( array( $post_id ) );
			$row = ( new PagesRepository() )->findByObject( 'post', $post_id );
			$this->assertIsArray( $row );
			$score = ( new ScoresRepository() )->latest( (int) $row['id'], 'rank tracker' );
			$this->assertIsArray( $score );

			$this->assertTrue( ( new AnalysePost() )->handle( $post_id ) );
			$model = ( new PagesRepository() )->loadModel( $post_id );
			$this->assertInstanceOf( PageModel::class, $model );
			$this->assertStringContainsString( 'Content sentence', $model->bodyText );
			$this->assertTrue( $model->hasExternalCss );
			$this->assertSame( 1200, $model->htmlBytes );
			$this->assertSame( 300, $model->visibleTextBytes );

			$again = ( new ScoresRepository() )->latest( (int) $row['id'], 'rank tracker' );
			$this->assertIsArray( $again );
			$details = json_decode( (string) $again['details'], true );
			$this->assertIsArray( $details );
			$stylesheet = null;
			foreach ( (array) ( $details['assessments'] ?? array() ) as $assessment ) {
				if ( is_array( $assessment ) && 'code.stylesheet' === ( $assessment['id'] ?? '' ) ) {
					$stylesheet = $assessment;
				}
			}
			$this->assertIsArray( $stylesheet );
			$this->assertNotSame( 'not_checked', $stylesheet['status'] );
		} finally {
			remove_all_filters( 'seostudio_live_model' );
		}
	}

	public function test_a_continued_fetch_passes_five_failures_and_refreshes_old_pages(): void {
		$ids = array();
		for ( $index = 0; $index < 6; $index++ ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_status'  => 'publish',
					'post_content' => '<p>Queued page ' . $index . '.</p>',
				)
			);
		}
		$good    = (int) $ids[5];
		$pages   = new PagesRepository();
		$page_id = $pages->storeModel(
			(int) $ids[0],
			new PageModel(
				url: 'https://example.test/already',
				renderMode: 'live'
			),
			'already'
		);
		$pages->markFetched( $page_id );

		$fetch = new FetchLive();
		$this->assertContains( (int) $ids[0], $fetch->nextIds( 0, 100 ) );
		$first = $fetch->nextIds( 0, 5 );
		$this->assertNotContains( $good, $first );
		$failed_id = 0;
		foreach ( $first as $candidate ) {
			if ( $candidate !== $good ) {
				$failed_id = $candidate;
				break;
			}
		}
		$this->assertGreaterThan( 0, $failed_id );

		$callback = static function ( mixed $model, int $post_id ) use ( $good ) {
			unset( $model );
			if ( $post_id === $good ) {
				return new PageModel(
					url: 'https://example.test/later',
					bodyText: 'Later page.',
					renderMode: 'live',
					hasExternalCss: true,
					htmlBytes: 800,
					visibleTextBytes: 200,
					metadataStatus: 'observed_html'
				);
			}

			return new WP_Error( 'seostudio_fetch_failed', 'blocked' );
		};
		add_filter( 'seostudio_live_model', $callback, 10, 2 );

		try {
			$fetch->handle( $first, true );
		} finally {
			remove_filter( 'seostudio_live_model', $callback, 10 );
		}

		$later = $pages->findByObject( 'post', $good );
		$this->assertIsArray( $later );
		$this->assertNotEmpty( $later['last_fetched_at'] );
		$this->assertNull( $later['last_error'] );

		$early = $pages->findByObject( 'post', $failed_id );
		$this->assertIsArray( $early );
		$this->assertNotEmpty( $early['last_error'] );
	}



	public function test_a_public_service_is_audited_when_it_is_saved(): void {
		register_post_type( 'service', array( 'public' => true ) );
		try {
			set_current_screen( 'edit-post' );
			$service_id = self::factory()->post->create(
				array(
					'post_type'    => 'service',
					'post_status'  => 'publish',
					'post_title'   => 'Garden service',
					'post_content' => '<p>Garden service copy.</p>',
				)
			);

			$this->assertContains( $service_id, ( new AuditBatch() )->nextIds( 0, 100 ) );
			$this->assertNotNull( ( new PagesRepository() )->findByObject( 'post', $service_id ) );
		} finally {
			set_current_screen( 'front' );
			unregister_post_type( 'service' );
		}
	}

	public function test_cancelling_during_the_final_batch_stays_cancelled(): void {
		$settings                   = Settings::all();
		$settings['batch_size']     = 100;
		$settings['schema_version'] = \SEOStudio\Core\Upgrader::CURRENT;
		update_option( Settings::OPTION, $settings, true );

		$ids  = ( new AuditBatch() )->nextIds( 0, 100 );
		$last = (int) ( array() === $ids ? 0 : $ids[ count( $ids ) - 1 ] );
		$made = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_content' => '<p>Final audit page.</p>',
			)
		);
		if ( $made > $last ) {
			$last = $made;
		}

		$callback = static function ( int $run_id, int $post_id ) use ( $last ): void {
			if ( $post_id !== $last ) {
				return;
			}
			( new AuditRunsRepository() )->finish( $run_id, 'cancelled' );
		};
		add_action( 'seostudio_audit_after_item', $callback, 10, 2 );

		try {
			$run_id = ( new AuditBatch() )->start();
		} finally {
			remove_action( 'seostudio_audit_after_item', $callback, 10 );
		}

		$run = ( new AuditRunsRepository() )->find( $run_id );
		$this->assertIsArray( $run );
		$this->assertSame( 'cancelled', $run['status'] );
	}
}
