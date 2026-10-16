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
}
