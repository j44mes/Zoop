<?php
/**
 * Site audit in resumable batches.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Jobs;

use SEOStudio\Core\Settings;
use SEOStudio\Storage\AuditRunsRepository;

/**
 * Scores the next slice of posts and re-queues itself with the cursor.
 */
final class AuditBatch {
	/**
	 * Statuses included in a site audit.
	 *
	 * @var string[]
	 */
	private const STATUSES = array( 'publish', 'draft', 'pending', 'future', 'private' );

	public function start(): int {
		$runs  = new AuditRunsRepository();
		$total = $this->countPosts();
		$run   = $runs->create( 'audit', $total );
		if ( 0 === $total ) {
			$runs->finish( $run, 'done' );
			return $run;
		}

		Scheduler::enqueue( 'seostudio_audit_batch', array( 'run_id' => $run ) );
		return $run;
	}

	public function handle( int $runId ): void {
		$runs = new AuditRunsRepository();
		$run  = $runs->find( $runId );
		if ( null === $run || in_array( (string) $run['status'], array( 'done', 'failed', 'cancelled' ), true ) ) {
			return;
		}

		$runs->markRunning( $runId );
		$settings = Settings::all();
		$batch    = max( 1, (int) $settings['batch_size'] );
		$cursor   = (int) $run['cursor_id'];
		$ids      = $this->nextIds( $cursor, $batch );
		$report   = json_decode( (string) ( $run['report'] ?? '' ), true );
		$report   = is_array( $report ) ? $report : array();
		$failures = isset( $report['failures'] ) && is_array( $report['failures'] ) ? $report['failures'] : array();

		$processed = (int) $run['processed'];
		$failed    = (int) $run['failed'];
		$last      = $cursor;
		$worker    = new AnalysePost();
		foreach ( $ids as $post_id ) {
			$last = $post_id;
			++$processed;
			if ( ! $worker->handle( $post_id ) ) {
				++$failed;
				$failures[] = array(
					'post_id' => $post_id,
					'message' => 'The post could not be rendered.',
				);
			}
			$pause = (int) $settings['batch_pause_ms'];
			if ( $pause > 0 && ! Scheduler::inline() ) {
				usleep( $pause * 1000 );
			}
		}

		$runs->advance(
			$runId,
			$processed,
			$failed,
			$last,
			array( 'failures' => $failures )
		);

		if ( array() !== $ids && count( $ids ) >= $batch && $this->hasMore( $last ) ) {
			Scheduler::enqueue( 'seostudio_audit_batch', array( 'run_id' => $runId ) );
			return;
		}

		$runs->finish( $runId, 'done' );
	}

	public function countPosts(): int {
		global $wpdb;

		$statuses = "'" . implode( "','", self::STATUSES ) . "'";
		$sql      = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('post', 'page') AND post_status IN ({$statuses})";
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Status list is a fixed internal set.
	}

	/**
	 * The next post IDs after the cursor.
	 *
	 * @return int[]
	 */
	public function nextIds( int $cursor, int $limit ): array {
		global $wpdb;

		$statuses = "'" . implode( "','", self::STATUSES ) . "'";
		$sql      = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('post', 'page') AND post_status IN ({$statuses}) AND ID > %d ORDER BY ID ASC LIMIT %d";
		$prepared = $wpdb->prepare( $sql, $cursor, $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Status list is a fixed internal set.
		if ( ! is_string( $prepared ) ) {
			return array();
		}
		$ids = $wpdb->get_col( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	private function hasMore( int $cursor ): bool {
		return array() !== $this->nextIds( $cursor, 1 );
	}
}
