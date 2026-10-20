<?php
/**
 * Local classification of a link target.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Architecture;

use SEOStudio\Content\Urls;
use SEOStudio\Jobs\PostTypes;

/**
 * Uses WordPress data already on this site. It does not fetch the address.
 */
final class DestinationResolver {
	/**
	 * Classify one absolute address from local WordPress data.
	 *
	 * @return array{status: string, reason: string}
	 */
	public function classify( string $absolute, string $home ): array {
		if ( UrlKey::systemPath( $absolute ) ) {
			return array(
				'status' => 'system',
				'reason' => __( 'WordPress system route.', 'seo-studio' ),
			);
		}
		if ( ! SiteBoundary::contains( $absolute, $home ) ) {
			return array(
				'status' => 'external',
				'reason' => __( 'Outside the public site.', 'seo-studio' ),
			);
		}

		$query_id = $this->queryId( $absolute );
		if ( null !== $query_id ) {
			$post = get_post( $query_id );
			return $this->fromPost( $post instanceof \WP_Post ? $post : null, true );
		}

		if ( function_exists( 'attachment_url_to_postid' ) ) {
			$attachment = (int) attachment_url_to_postid( $absolute );
			if ( $attachment > 0 ) {
				$post = get_post( $attachment );
				return $this->fromPost( $post instanceof \WP_Post ? $post : null, false, true );
			}
		}

		if ( function_exists( 'url_to_postid' ) ) {
			$post_id = (int) url_to_postid( $absolute );
			if ( $post_id > 0 ) {
				$post = get_post( $post_id );
				return $this->fromPost( $post instanceof \WP_Post ? $post : null, false );
			}
		}

		$historical = $this->historicalSlug( $absolute, $home );
		if ( null !== $historical ) {
			return $historical;
		}

		$archive = $this->archive( $absolute, $home );
		if ( null !== $archive ) {
			return $archive;
		}

		$local = $this->localPost( $absolute, $home );
		if ( null !== $local ) {
			return $local;
		}

		return array(
			'status' => 'unknown',
			'reason' => __( 'No local WordPress route matched this address. It is not a confirmed broken link.', 'seo-studio' ),
		);
	}

	private function queryId( string $absolute ): ?int {
		$query = Urls::component( $absolute, PHP_URL_QUERY );
		if ( ! is_string( $query ) || '' === $query ) {
			return null;
		}
		$args = array();
		parse_str( $query, $args );
		foreach ( array( 'p', 'page_id', 'attachment_id' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_numeric( $args[ $key ] ) ) {
				return (int) $args[ $key ];
			}
		}
		return null;
	}

	/**
	 * Classify a post WordPress already loaded.
	 *
	 * @return array{status: string, reason: string}
	 */
	private function fromPost( ?\WP_Post $post, bool $query, bool $media_url = false ): array {
		if ( ! $post instanceof \WP_Post ) {
			return array(
				'status' => 'missing',
				'reason' => __( 'The address names a post that is not in WordPress.', 'seo-studio' ),
			);
		}
		if ( 'trash' === $post->post_status ) {
			return array(
				'status' => 'missing',
				'reason' => __( 'The post is in the trash.', 'seo-studio' ),
			);
		}
		if ( 'attachment' === $post->post_type || $media_url ) {
			$mime = (string) get_post_mime_type( $post );
			$pdf  = 'application/pdf' === $mime;
			return array(
				'status' => 'media',
				'reason' => $pdf
					? __( 'PDF attachment.', 'seo-studio' )
					: __( 'Media attachment.', 'seo-studio' ),
			);
		}
		if ( ! in_array( $post->post_status, array( 'publish', 'private', 'draft', 'pending', 'future' ), true ) ) {
			return array(
				'status' => 'unknown',
				'reason' => __( 'The post status is not a public destination.', 'seo-studio' ),
			);
		}
		if ( in_array( $post->post_status, array( 'private', 'draft', 'pending', 'future' ), true ) ) {
			return array(
				'status' => 'non_public',
				'reason' => __( 'The destination exists and is not public.', 'seo-studio' ),
			);
		}
		if ( $query ) {
			return array(
				'status' => 'query',
				'reason' => __( 'Valid query permalink.', 'seo-studio' ),
			);
		}
		return array(
			'status' => 'published',
			'reason' => __( 'Published content.', 'seo-studio' ),
		);
	}

	/**
	 * A previous slug whose full route still matches this post type and permalink shape.
	 *
	 * A shared basename on an unrelated path is not a redirect and not a confirmed missing page.
	 *
	 * @return array{status: string, reason: string}|null
	 */
	private function historicalSlug( string $absolute, string $home ): ?array {
		global $wpdb;
		if ( ! $wpdb instanceof \wpdb ) {
			return null;
		}
		$relative = $this->relativePath( $absolute, $home );
		$slug     = '' !== $relative ? basename( $relative ) : '';
		if ( '' === $slug ) {
			return null;
		}
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_old_slug' AND meta_value = %s LIMIT 20",
				$slug
			)
		);
		if ( ! is_array( $ids ) ) {
			return null;
		}
		$matched = null;
		foreach ( $ids as $raw_id ) {
			$post = get_post( (int) $raw_id );
			if ( ! $post instanceof \WP_Post || ! $this->historicalPathMatches( $post, $relative, $slug ) ) {
				continue;
			}
			$matched = $post;
			if ( 'publish' === $post->post_status ) {
				break;
			}
		}
		if ( ! $matched instanceof \WP_Post ) {
			return null;
		}
		if ( 'publish' === $matched->post_status ) {
			return array(
				'status' => 'redirect',
				'reason' => __( 'Known historical slug for a published post.', 'seo-studio' ),
			);
		}
		return $this->fromPost( $matched, false );
	}

	/**
	 * Whether this path is the route WordPress would have used for the old slug.
	 */
	private function historicalPathMatches( \WP_Post $post, string $relative, string $oldSlug ): bool {
		$expected = $this->expectedHistoricalPath( $post, $oldSlug );
		return '' !== $expected && trim( $relative, '/' ) === $expected;
	}

	/**
	 * Permalink path for this post when its slug was the stored historical value.
	 */
	private function expectedHistoricalPath( \WP_Post $post, string $oldSlug ): string {
		if ( is_post_type_hierarchical( $post->post_type ) ) {
			$parts  = array( $oldSlug );
			$parent = (int) $post->post_parent;
			$guard  = 0;
			while ( $parent > 0 && $guard < 20 ) {
				$ancestor = get_post( $parent );
				if ( ! $ancestor instanceof \WP_Post || '' === (string) $ancestor->post_name ) {
					return '';
				}
				array_unshift( $parts, (string) $ancestor->post_name );
				$parent = (int) $ancestor->post_parent;
				++$guard;
			}
			if ( 'page' !== $post->post_type ) {
				$prefix = $this->rewriteSlug( $post->post_type );
				if ( '' !== $prefix ) {
					array_unshift( $parts, $prefix );
				}
			}
			return implode( '/', $parts );
		}

		$structure = trim( (string) get_option( 'permalink_structure', '' ), '/' );
		if ( '' === $structure || ! str_contains( $structure, '%postname%' ) ) {
			return '';
		}
		$stamp    = strtotime( (string) $post->post_date );
		$stamp    = false === $stamp ? time() : $stamp;
		$category = 'uncategorized';
		if ( function_exists( 'get_the_category' ) ) {
			$terms = get_the_category( $post->ID );
			if ( is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof \WP_Term && '' !== (string) $terms[0]->slug ) {
				$category = (string) $terms[0]->slug;
			}
		}
		$author = '';
		if ( function_exists( 'get_userdata' ) ) {
			$user   = get_userdata( (int) $post->post_author );
			$author = $user instanceof \WP_User ? (string) $user->user_nicename : '';
		}
		$path = strtr(
			$structure,
			array(
				'%year%'     => gmdate( 'Y', $stamp ),
				'%monthnum%' => gmdate( 'm', $stamp ),
				'%day%'      => gmdate( 'd', $stamp ),
				'%hour%'     => gmdate( 'H', $stamp ),
				'%minute%'   => gmdate( 'i', $stamp ),
				'%second%'   => gmdate( 's', $stamp ),
				'%post_id%'  => (string) $post->ID,
				'%postname%' => $oldSlug,
				'%category%' => $category,
				'%author%'   => $author,
			)
		);
		if ( str_contains( $path, '%' ) ) {
			return '';
		}
		if ( 'post' !== $post->post_type ) {
			$prefix = $this->rewriteSlug( $post->post_type );
			if ( '' !== $prefix && ! str_starts_with( trim( $path, '/' ), $prefix . '/' ) && trim( $path, '/' ) !== $prefix ) {
				$path = $prefix . '/' . trim( $path, '/' );
			}
		}
		return trim( $path, '/' );
	}

	private function rewriteSlug( string $postType ): string {
		if ( ! function_exists( 'get_post_type_object' ) ) {
			return '';
		}
		$object = get_post_type_object( $postType );
		if ( ! is_object( $object ) ) {
			return '';
		}
		$rewrite = $object->rewrite;
		if ( ! is_array( $rewrite ) || ! isset( $rewrite['slug'] ) ) {
			return '';
		}
		return trim( (string) $rewrite['slug'], '/' );
	}

	/**
	 * Category, tag, and author routes under the public home path.
	 *
	 * @return array{status: string, reason: string}|null
	 */
	private function archive( string $absolute, string $home ): ?array {
		$relative = $this->relativePath( $absolute, $home );
		if ( '' === $relative || ! function_exists( 'get_term_by' ) ) {
			return null;
		}

		$category_base = trim( (string) get_option( 'category_base', 'category' ), '/' );
		$tag_base      = trim( (string) get_option( 'tag_base', 'tag' ), '/' );
		$category_base = '' !== $category_base ? $category_base : 'category';
		$tag_base      = '' !== $tag_base ? $tag_base : 'tag';

		$term = $this->prefixedTerm( $relative, $category_base, 'category' );
		if ( null !== $term ) {
			return $term;
		}
		$term = $this->prefixedTerm( $relative, $tag_base, 'post_tag' );
		if ( null !== $term ) {
			return $term;
		}
		if ( str_starts_with( $relative, 'author/' ) && function_exists( 'get_user_by' ) ) {
			$user = get_user_by( 'slug', basename( $relative ) );
			if ( $user instanceof \WP_User ) {
				return array(
					'status' => 'archive',
					'reason' => __( 'Author archive.', 'seo-studio' ),
				);
			}
		}
		return null;
	}

	/**
	 * A taxonomy path under a known base.
	 *
	 * @return array{status: string, reason: string}|null
	 */
	private function prefixedTerm( string $relative, string $base, string $taxonomy ): ?array {
		$prefix = $base . '/';
		if ( ! str_starts_with( $relative, $prefix ) ) {
			return null;
		}
		$slug = basename( $relative );
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( $term instanceof \WP_Term ) {
			return array(
				'status' => 'archive',
				'reason' => 'category' === $taxonomy
					? __( 'Category archive.', 'seo-studio' )
					: __( 'Tag archive.', 'seo-studio' ),
			);
		}
		return array(
			'status' => 'unknown',
			'reason' => __( 'The archive path has no matching term. It is not a confirmed broken link.', 'seo-studio' ),
		);
	}

	/**
	 * A local post whose public route WordPress did not resolve, including private posts.
	 *
	 * @return array{status: string, reason: string}|null
	 */
	private function localPost( string $absolute, string $home ): ?array {
		if ( ! function_exists( 'get_page_by_path' ) ) {
			return null;
		}
		$relative = $this->relativePath( $absolute, $home );
		if ( '' === $relative ) {
			return null;
		}
		$post = get_page_by_path( $relative, OBJECT, PostTypes::public() );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		return $this->fromPost( $post, false );
	}

	private function relativePath( string $absolute, string $home ): string {
		$path      = Urls::component( $absolute, PHP_URL_PATH );
		$home_path = SiteBoundary::publicPath( $home );
		$relative  = is_string( $path ) ? $path : '/';
		if ( '/' !== $home_path && str_starts_with( $relative, $home_path ) ) {
			$relative = substr( $relative, strlen( $home_path ) );
		}
		return trim( (string) $relative, '/' );
	}
}
