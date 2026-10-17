<?php
/**
 * Real queued nightly sweeps under external cron.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Queue;

use SEOStudio\Content\PageModel;
use SEOStudio\Core\Plugin;
use SEOStudio\Jobs\FetchLive;
use SEOStudio\Jobs\Scheduler;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Storage\ScoresRepository;
use WP_Error;
use WP_UnitTestCase;

/**
 * Drain persisted Action Scheduler actions; never call the batch handler inline.
 */
final class ExternalCronTest extends WP_UnitTestCase {
	public function test_external_runner_completes_and_repeats_a_sweep_after_a_failure(): void {
		Plugin::activate();
		do_action( 'init' );
		$this->assertTrue( Scheduler::cronDisabled() );
		$this->assertFalse( Scheduler::inline() );
		register_post_type( 'queue_fixture', array( 'public' => true ) );
		$ids = array();
		for ( $i = 0; $i < 12; ++$i ) {
			$ids[] = self::factory()->post->create( array( 'post_status' => 'publish', 'post_type' => 11 === $i ? 'queue_fixture' : 'post' ) );
			update_post_meta( $ids[$i], '_seostudio_focus_keyphrase', 'fixture' );
		}
		$visits = array();
		$forced = static function ( mixed $value, int $postId ) use ( &$visits, $ids ): PageModel|WP_Error {
			$visits[$postId] = ( $visits[$postId] ?? 0 ) + 1;
			return $postId === $ids[2] ? new WP_Error( 'seostudio_fetch_failed', 'Fixture failure' ) : new PageModel( url: (string) get_permalink( $postId ), titleTag: 'Fixture page', renderMode: 'live' );
		};
		add_filter( 'seostudio_live_model', $forced, 10, 2 );
		try {
			for ( $sweep = 1; $sweep <= 2; ++$sweep ) {
				( new FetchLive() )->nightly();
				$this->assertNotEmpty( $this->pending(), 'The nightly sweep must persist queued work.' );
				for ( $attempt = 0; $attempt < 10 && array() !== $this->pending(); ++$attempt ) {
					\ActionScheduler_QueueRunner::instance()->run( 'External cron test' );
				}
				$this->assertSame( array(), $this->pending() );
				foreach ( $ids as $post_id ) {
					$this->assertSame( $sweep, $visits[$post_id] ?? 0 );
					$row = ( new PagesRepository() )->findByObject( 'post', $post_id );
					$this->assertIsArray( $row );
					if ( $post_id === $ids[2] ) {
						$this->assertSame( 'Fixture failure', $row['last_error'] );
					} else {
						$this->assertNotEmpty( $row['last_fetched_at'] );
						$this->assertNotNull( ( new ScoresRepository() )->latest( (int) $row['id'], 'fixture' ) );
					}
				}
			}
			$before = $visits;
			( new FetchLive() )->handle( array( $ids[0] ) );
			$before[$ids[0]]++;
			$this->assertSame( $before, $visits, 'A single Verify-live-style fetch must not start a sweep.' );
			$this->assertSame( array(), $this->pending() );
		} finally {
			remove_filter( 'seostudio_live_model', $forced, 10 );
			unregister_post_type( 'queue_fixture' );
		}
	}

	/**
	 * @return int[]
	 */
	private function pending(): array {
		return as_get_scheduled_actions( array( 'hook' => 'seostudio_fetch_live', 'group' => Scheduler::GROUP, 'status' => 'pending', 'per_page' => 100 ), 'ids' );
	}
}
