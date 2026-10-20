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

	/**
	 * Provenance for the search-status label. Content renders stay unobserved.
	 *
	 * @return array{status: string, source: string}
	 */
	public static function describe( int $postId, PageModel $model ): array {
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post || ! is_post_publicly_viewable( $post ) || '' !== $post->post_password ) {
			return array(
				'status' => 'not_public',
				'source' => 'visibility',
			);
		}

		$live = 'live' === $model->renderMode && 'observed_html' === $model->metadataStatus;
		if ( $live && ( self::directive( $model->robotsMeta ) || self::directive( $model->robotsHttp ) ) ) {
			$meta = self::directive( $model->robotsMeta );
			$http = self::directive( $model->robotsHttp );
			return array(
				'status' => 'noindex',
				'source' => $meta && $http ? 'meta+http' : ( $http ? 'http' : 'meta' ),
			);
		}

		if ( '0' === (string) get_option( 'blog_public' ) ) {
			return array(
				'status' => 'site_discouraged',
				'source' => 'blog_public',
			);
		}

		if ( $live ) {
			return array(
				'status' => 'indexable',
				'source' => 'response',
			);
		}

		return array(
			'status' => 'unobserved',
			'source' => '',
		);
	}

	private static function directive( string $value ): bool {
		return 1 === preg_match( '/(?:^|[\s,:])(?:noindex|none)(?:$|[\s,])/i', $value );
	}
}
