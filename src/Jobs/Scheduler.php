<?php
/**
 * Action Scheduler wiring.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Jobs;

/**
 * Queues Zoop jobs in the seostudio group.
 */
final class Scheduler {
	public const GROUP = 'seostudio';

	public static function boot(): void {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;

		add_action( 'seostudio_analyse_post', array( self::class, 'analysePost' ) );
		add_action( 'seostudio_audit_batch', array( self::class, 'auditBatch' ) );
		add_action( 'seostudio_fetch_live', array( self::class, 'fetchLive' ), 10, 2 );
		add_action( 'seostudio_import_batch', array( self::class, 'importBatch' ), 10, 3 );
		add_action( 'seostudio_fetch_nightly', array( self::class, 'fetchNightly' ) );
		add_action( 'save_post', array( self::class, 'onSave' ), 20, 2 );
		self::scheduleNightly();
	}

	/**
	 * Queue a job. Tests set SEOSTUDIO_JOBS_SYNC so the batch runs in the request.
	 *
	 * @param array<string, mixed> $args
	 */
	public static function enqueue( string $hook, array $args = array(), int $delay = 0 ): void {
		if ( self::inline() || ! function_exists( 'as_enqueue_async_action' ) ) {
			self::dispatch( $hook, $args );
			return;
		}

		if ( $delay > 0 && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP );
			return;
		}

		as_enqueue_async_action( $hook, $args, self::GROUP );
	}

	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			foreach ( self::hooks() as $hook ) {
				as_unschedule_all_actions( $hook, array(), self::GROUP );
			}
			return;
		}

		global $wpdb;
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$groups  = $wpdb->prefix . 'actionscheduler_groups';
		$found   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $actions ) );
		if ( $found !== $actions ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"DELETE actions FROM {$actions} actions INNER JOIN {$groups} groups ON actions.group_id = groups.group_id WHERE groups.slug = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names use the site prefix.
				self::GROUP
			)
		);
	}

	/**
	 * Job hooks this plugin owns.
	 *
	 * @return string[]
	 */
	public static function hooks(): array {
		return array(
			'seostudio_analyse_post',
			'seostudio_audit_batch',
			'seostudio_fetch_live',
			'seostudio_import_batch',
			'seostudio_fetch_nightly',
		);
	}

	/**
	 * Debounce a single-post analysis after an admin, REST, cron, or CLI save.
	 *
	 * @param mixed $post
	 */
	public static function onSave( int $post_id, mixed $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! self::contextAllowsWork() ) {
			return;
		}
		if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, PostTypes::public(), true ) ) {
			return;
		}

		self::enqueue( 'seostudio_analyse_post', array( 'post_id' => $post_id ), 5 );
	}

	public static function analysePost( int $post_id ): void {
		( new AnalysePost() )->handle( $post_id );
	}

	public static function auditBatch( int $run_id ): void {
		( new AuditBatch() )->handle( $run_id );
	}

	/**
	 * Fetch the queued post IDs. A continued batch queues the next cursor.
	 *
	 * @param int[] $post_ids
	 */
	public static function fetchLive( array $post_ids, int $resume = 0 ): void {
		( new FetchLive() )->handle( $post_ids, 1 === $resume );
	}

	public static function importBatch( string $source, int $cursor, int $run_id ): void {
		( new ImportBatch() )->handle( $source, $cursor, $run_id );
	}

	public static function fetchNightly(): void {
		( new FetchLive() )->nightly();
	}

	public static function inline(): bool {
		return defined( 'SEOSTUDIO_JOBS_SYNC' ) && SEOSTUDIO_JOBS_SYNC;
	}

	public static function cronDisabled(): bool {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	/**
	 * The command shown when WP-Cron will not drain the queue.
	 */
	public static function cronCommand(): string {
		return 'wp action-scheduler run --group=' . self::GROUP;
	}

	/**
	 * Run one job in this request.
	 *
	 * @param array<string, mixed> $args
	 */
	private static function dispatch( string $hook, array $args ): void {
		if ( 'seostudio_analyse_post' === $hook ) {
			self::analysePost( (int) ( $args['post_id'] ?? 0 ) );
			return;
		}
		if ( 'seostudio_audit_batch' === $hook ) {
			self::auditBatch( (int) ( $args['run_id'] ?? 0 ) );
			return;
		}
		if ( 'seostudio_fetch_live' === $hook ) {
			$ids = isset( $args['post_ids'] ) && is_array( $args['post_ids'] ) ? array_map( 'intval', $args['post_ids'] ) : array();
			self::fetchLive( $ids, (int) ( $args['continue'] ?? 0 ) );
			return;
		}
		if ( 'seostudio_import_batch' === $hook ) {
			self::importBatch( (string) ( $args['source'] ?? '' ), (int) ( $args['cursor'] ?? 0 ), (int) ( $args['run_id'] ?? 0 ) );
			return;
		}
		if ( 'seostudio_fetch_nightly' === $hook ) {
			self::fetchNightly();
		}
	}

	private static function scheduleNightly(): void {
		if ( self::inline() || ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( as_has_scheduled_action( 'seostudio_fetch_nightly', array(), self::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, 'seostudio_fetch_nightly', array(), self::GROUP );
	}

	private static function contextAllowsWork(): bool {
		if ( is_admin() ) {
			return true;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return true;
		}

		return defined( 'WP_CLI' ) && WP_CLI;
	}
}
