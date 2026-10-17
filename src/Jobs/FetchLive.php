<?php
/**
 * Queued same-host fetches.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Jobs;

use SEOStudio\Content\LoopbackFetcher;
use SEOStudio\Content\PageModel;
use SEOStudio\Content\Renderer;
use SEOStudio\Storage\PagesRepository;

/**
 * Nightly code-check fetches. Verify live stays synchronous when cron is disabled.
 */
final class FetchLive {
	public function __construct( private readonly LoopbackFetcher $fetcher = new LoopbackFetcher() ) {}

	public function nightly(): void {
		$ids = $this->pendingIds( 5 );
		if ( array() === $ids ) {
			return;
		}
		if ( Scheduler::cronDisabled() ) {
			$this->handle( array( $ids[0] ) );
			return;
		}

		Scheduler::enqueue( 'seostudio_fetch_live', array( 'post_ids' => $ids ) );
	}

	/**
	 * Fetch each URL, pausing between them outside tests.
	 *
	 * @param int[] $postIds
	 */
	public function handle( array $postIds ): void {
		$pages  = new PagesRepository();
		$render = new Renderer();
		foreach ( array_values( $postIds ) as $index => $post_id ) {
			if ( $index > 0 && ! Scheduler::inline() && ! Scheduler::cronDisabled() ) {
				sleep( 2 );
			}
			$this->fetchOne( $post_id, $pages, $render );
		}
	}

	/**
	 * Published posts that have never been fetched.
	 *
	 * @return int[]
	 */
	public function pendingIds( int $limit ): array {
		global $wpdb;

		$pages    = \SEOStudio\Storage\Tables::pages();
		$sql      = "SELECT posts.ID FROM {$wpdb->posts} posts
			LEFT JOIN {$pages} pages ON pages.object_type = 'post' AND pages.object_id = posts.ID
			WHERE posts.post_type IN ('post', 'page') AND posts.post_status = 'publish' AND pages.last_fetched_at IS NULL
			ORDER BY posts.ID ASC LIMIT %d";
		$prepared = $wpdb->prepare( $sql, $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The joined table name is internal.
		if ( ! is_string( $prepared ) ) {
			return array();
		}
		$ids = $wpdb->get_col( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	private function fetchOne( int $postId, PagesRepository $pages, Renderer $render ): void {
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		try {
			$forced = apply_filters( 'seostudio_live_model', null, $postId );
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
		} catch ( \Throwable $exception ) {
			$pages->recordError( $postId, $exception->getMessage() );
		}
	}
}
