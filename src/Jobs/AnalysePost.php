<?php
/**
 * Scores one saved post.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Jobs;

use SEOStudio\Content\PageModel;
use SEOStudio\Content\Renderer;
use SEOStudio\Scoring\OnPage\Scorer;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Storage\ScoresRepository;

/**
 * Content-render analysis for a single post. A missing keyphrase still stores the page.
 */
final class AnalysePost {
	public function handle( int $postId ): bool {
		if ( $postId <= 0 || ! get_post( $postId ) ) {
			return false;
		}

		try {
			$pages   = new PagesRepository();
			$model   = $pages->mergeMeasurements( $postId, ( new Renderer() )->renderPost( $postId ) );
			$page_id = $pages->storeModel( $postId, $model, $model->inputHash() );
			$stored  = $pages->loadModel( $postId );
			if ( $stored instanceof PageModel ) {
				$model = $stored;
			}
			$phrase = get_post_meta( $postId, '_seostudio_focus_keyphrase', true );
			if ( is_string( $phrase ) && '' !== trim( $phrase ) ) {
				$score = ( new Scorer() )->score( $model, $phrase );
				( new ScoresRepository() )->insert( $page_id, $phrase, $score, SEOSTUDIO_VERSION, $model->inputHash() );
			}
			return true;
		} catch ( \Throwable $exception ) {
			( new PagesRepository() )->recordError( $postId, $exception->getMessage() );
			return false;
		}
	}
}
