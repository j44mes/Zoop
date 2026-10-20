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
		if ( self::blocksIndex( $model->robots ) ) {
			return false;
		}
		return 'live' === $model->renderMode && 'observed_html' === $model->metadataStatus ? true : null;
	}

	/**
	 * Whether a robots value contains a standalone noindex or none directive.
	 *
	 * Parameter values such as max-image-preview:none are not directives.
	 */
	public static function blocksIndex( string $value ): bool {
		foreach ( self::directives( $value ) as $directive ) {
			if ( in_array( $directive, array( 'noindex', 'none' ), true ) ) {
				return true;
			}
		}

		return false;
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
		if ( $live && ( self::blocksIndex( $model->robotsMeta ) || self::blocksIndex( $model->robotsHttp ) ) ) {
			$meta = self::blocksIndex( $model->robotsMeta );
			$http = self::blocksIndex( $model->robotsHttp );
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

	/**
	 * Comma-separated robots tokens, with an optional googlebot prefix removed.
	 *
	 * @return string[]
	 */
	private static function directives( string $value ): array {
		$parts = preg_split( '/\s*,\s*/', strtolower( $value ) );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$directives = array();
		foreach ( $parts as $part ) {
			$token = trim( (string) $part );
			if ( '' === $token ) {
				continue;
			}
			if ( 1 === preg_match( '/^googlebot\s*:\s*(.+)$/', $token, $matches ) ) {
				$token = trim( $matches[1] );
			}
			if ( '' === $token || str_contains( $token, ':' ) ) {
				continue;
			}
			$directives[] = $token;
		}

		return $directives;
	}
}
