<?php
/**
 * Audit run records.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Storage;

/**
 * Persistence for batch runs. The worker itself arrives in Phase 3.
 */
final class AuditRunsRepository {
	/**
	 * Insert a queued audit run.
	 */
	public function create( string $type, int $total = 0 ): int {
		global $wpdb;

		$wpdb->insert(
			Tables::auditRuns(),
			array(
				'type'       => $type,
				'status'     => 'queued',
				'total'      => $total,
				'processed'  => 0,
				'failed'     => 0,
				'cursor_id'  => 0,
				'started_at' => current_time( 'mysql', true ),
				'report'     => wp_json_encode( array() ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Load an audit run by its id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $runId ): ?array {
		global $wpdb;
		$table = Tables::auditRuns();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $runId ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * A queued or running run of this type, if one exists.
	 *
	 * @return array<string, mixed>|null
	 */
	public function active( string $type ): ?array {
		global $wpdb;

		$table = Tables::auditRuns();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE type = %s AND status IN ('queued', 'running') ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				$type
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	public function markRunning( int $runId ): void {
		global $wpdb;

		$wpdb->update(
			Tables::auditRuns(),
			array( 'status' => 'running' ),
			array( 'id' => $runId )
		);
	}

	/**
	 * Persist progress after a batch.
	 *
	 * @param array<string, mixed> $report
	 */
	public function advance( int $runId, int $processed, int $failed, int $cursor, array $report ): void {
		global $wpdb;

		$wpdb->update(
			Tables::auditRuns(),
			array(
				'processed' => $processed,
				'failed'    => $failed,
				'cursor_id' => $cursor,
				'report'    => wp_json_encode( $report ),
			),
			array( 'id' => $runId )
		);
	}

	public function finish( int $runId, string $status ): void {
		global $wpdb;

		$wpdb->update(
			Tables::auditRuns(),
			array(
				'status'      => $status,
				'finished_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $runId )
		);
	}

	/**
	 * Public shape returned by the audit routes.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public function present( array $row ): array {
		$report = json_decode( (string) ( $row['report'] ?? '' ), true );

		return array(
			'run_id'    => (int) $row['id'],
			'type'      => (string) $row['type'],
			'status'    => (string) $row['status'],
			'total'     => (int) $row['total'],
			'processed' => (int) $row['processed'],
			'failed'    => (int) $row['failed'],
			'cursor'    => (int) $row['cursor_id'],
			'report'    => is_array( $report ) ? $report : array(),
		);
	}
}
