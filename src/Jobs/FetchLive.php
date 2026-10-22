<?php
/**
 * Queued same-host fetches.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Jobs;

use SEOStudio\Content\FetchBlockedException;
use SEOStudio\Content\LoopbackFetcher;
use SEOStudio\Content\PageModel;
use SEOStudio\Content\Renderer;
use SEOStudio\Scoring\OnPage\Scorer;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Storage\ScoresRepository;

/**
 * Nightly code-check fetches. Scheduled sweeps also support external queue runners.
 */
final class FetchLive {
	public function __construct( private readonly LoopbackFetcher $fetcher = new LoopbackFetcher() ) {}

	/**
	 * Start a sweep at the first published page.
	 *
	 * DISABLE_WP_CRON stops automatic spawning, not an external Action Scheduler runner.
	 */
	public function nightly(): void {
		$this->queueFrom( 0 );
	}

	/**
	 * Queue the next five published pages after the cursor.
	 */
	public function queueFrom( int $cursor ): void {
		$ids = $this->nextIds( $cursor, 5 );
		if ( array() === $ids ) {
			return;
		}

		Scheduler::enqueue(
			'seostudio_fetch_live',
			array(
				'post_ids' => $ids,
				'continue' => 1,
			)
		);
	}

	/**
	 * Fetch each URL, pausing between them outside tests.
	 *
	 * A full batch with continuation queues the following IDs, including pages fetched before.
	 *
	 * @param int[] $postIds
	 */
	public function handle( array $postIds, bool $chain = false ): void {
		$pages  = new PagesRepository();
		$render = new Renderer();
		$ids    = array_values( array_map( 'intval', $postIds ) );
		foreach ( $ids as $index => $post_id ) {
			if ( $index > 0 && ! Scheduler::inline() ) {
				sleep( 2 );
			}
			$this->fetchOne( $post_id, $pages, $render );
		}

		if ( ! $chain || count( $ids ) < 5 ) {
			return;
		}

		$last = (int) $ids[ count( $ids ) - 1 ];
		if ( $this->hasMore( $last ) ) {
			$this->queueFrom( $last );
		}
	}

	/**
	 * Published public content after the cursor, including pages fetched on an earlier sweep.
	 *
	 * @return int[]
	 */
	public function nextIds( int $cursor, int $limit ): array {
		global $wpdb;

		$types    = PostTypes::public();
		$type_sql = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql      = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$type_sql}) AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d";
		$prepared = $wpdb->prepare( $sql, ...array_merge( $types, array( $cursor, $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders are internal. The values are prepared.
		if ( ! is_string( $prepared ) ) {
			return array();
		}
		$ids = $wpdb->get_col( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	private function hasMore( int $cursor ): bool {
		return array() !== $this->nextIds( $cursor, 1 );
	}

	private function fetchOne( int $postId, PagesRepository $pages, Renderer $render ): void {
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		try {
			// Test seam only: production always performs the guarded loopback fetch.
			$forced = defined( 'SEOSTUDIO_TESTING' ) && SEOSTUDIO_TESTING ? apply_filters( 'seostudio_live_model', null, $postId ) : null;
			if ( $forced instanceof \WP_Error ) {
				throw new \RuntimeException( $forced->get_error_message() );
			}
			if ( $forced instanceof PageModel ) {
				$model = $forced;
			} else {
				$url   = get_permalink( $post );
				$model = $this->fetcher->fetch(
					is_string( $url ) ? $url : '',
					array(
						'page_type'        => $post->post_type,
						'content_selector' => $render->selectorFor( $post ),
					)
				);
			}
			$page_id = $pages->storeModel( $postId, $model, $model->inputHash() );
			$pages->markFetched( $page_id );
			$stored = $pages->loadModel( $postId );
			$this->score( $postId, $page_id, $stored instanceof PageModel ? $stored : $model );
		} catch ( FetchBlockedException $exception ) {
			$pages->recordFetchFailure( $postId, $exception );
		} catch ( \Throwable $exception ) {
			$pages->recordError( $postId, $exception->getMessage() );
		}
	}

	/**
	 * Store an on-page score for the fetched model when the post has a focus keyphrase.
	 */
	private function score( int $postId, int $pageId, PageModel $model ): void {
		$phrase = get_post_meta( $postId, '_seostudio_focus_keyphrase', true );
		if ( ! is_string( $phrase ) || '' === trim( $phrase ) ) {
			return;
		}

		$score = ( new Scorer() )->score( $model, $phrase );
		( new ScoresRepository() )->insert( $pageId, $phrase, $score, SEOSTUDIO_VERSION, $model->inputHash() );
	}
}
