<?php
/**
 * Migration batches.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Jobs;

use SEOStudio\Integrations\Importers\Catalogue;
use SEOStudio\Storage\AuditRunsRepository;

/**
 * Copies another plugin's meta in slices of 200 posts.
 */
final class ImportBatch {
	public function start( string $source ): int {
		$importer = Catalogue::make( $source );
		$runs     = new AuditRunsRepository();
		$counts   = $importer->counts();
		$run      = $runs->create( 'import', (int) $counts['posts'] );
		$runs->advance(
			$run,
			0,
			0,
			0,
			array(
				'source' => $importer->source(),
				'counts' => $counts,
			)
		);
		Scheduler::enqueue(
			'seostudio_import_batch',
			array(
				'source' => $importer->source(),
				'cursor' => 0,
				'run_id' => $run,
			)
		);
		return $run;
	}

	public function handle( string $source, int $cursor, int $runId ): void {
		$runs = new AuditRunsRepository();
		$run  = $runs->find( $runId );
		if ( null === $run || in_array( (string) $run['status'], array( 'done', 'cancelled', 'failed' ), true ) ) {
			return;
		}

		$runs->markRunning( $runId );
		$importer = Catalogue::make( $source );
		$result   = $importer->import( $cursor, 200 );
		$report   = json_decode( (string) ( $run['report'] ?? '' ), true );
		$report   = is_array( $report ) ? $report : array();
		$runs->advance(
			$runId,
			(int) $run['processed'] + (int) $result['processed'],
			(int) $run['failed'],
			(int) $result['cursor'],
			$report
		);

		if ( empty( $result['done'] ) ) {
			Scheduler::enqueue(
				'seostudio_import_batch',
				array(
					'source' => $importer->source(),
					'cursor' => (int) $result['cursor'],
					'run_id' => $runId,
				)
			);
			return;
		}

		$runs->finish( $runId, 'done' );
	}
}
