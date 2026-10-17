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
use SEOStudio\Jobs\AuditBatch;
use SEOStudio\Jobs\FetchLive;
use SEOStudio\Storage\AuditRunsRepository;
use SEOStudio\Storage\PagesRepository;
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
		$settings                = Settings::all();
		$settings['batch_size']  = 10;
		$settings['schema_version'] = 2;
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
}
