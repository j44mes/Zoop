<?php
/**
 * Conservative indexability checks for saved analysis.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Storage;

use SEOStudio\Content\PageModel;

/**
 * Null means the public response has not been checked.
 */
final class Indexability {
	public static function forPost( int $postId, PageModel $model ): ?bool {
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post || ! is_post_publicly_viewable( $post ) || '' !== $post->post_password || '0' === (string) get_option( 'blog_public' ) ) {
			return false;
		}
		if ( preg_match( '/(?:^|[\s,:])(?:noindex|none)(?:$|[\s,])/i', $model->robots ) ) {
			return false;
		}
		return 'live' === $model->renderMode && 'observed_html' === $model->metadataStatus ? true : null;
	}
}
