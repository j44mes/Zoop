<?php
/**
 * Renders a post into a PageModel without an HTTP request.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Content;

/**
 * In-process content rendering for drafts and batch audits.
 */
final class Renderer {
	public function __construct( private readonly Extractor $extractor = new Extractor() ) {}

	/**
	 * From content.
	 *
	 * @param array<string, mixed> $context
	 */
	public function fromContent( string $html, array $context = array() ): PageModel {
		$context['render_mode'] = 'content';
		return $this->extractor->extract( $html, $context );
	}

	/**
	 * Render a saved post through the_content. Requires WordPress.
	 *
	 * @throws \RuntimeException When the post does not exist.
	 */
	public function renderPost( int $postId ): PageModel {
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			throw new \RuntimeException( 'Post not found.' );
		}

		$query = new \WP_Query(
			array(
				'p'              => $postId,
				'post_type'      => $post->post_type,
				'post_status'    => 'any',
				'posts_per_page' => 1,
			)
		);

		$html = '';
		if ( $query->have_posts() ) {
			$query->the_post();
			$html = (string) apply_filters( 'the_content', $post->post_content );
			wp_reset_postdata();
		}

		$url = get_permalink( $post );
		$url = is_string( $url ) ? $url : '';

		return $this->fromContent(
			$html,
			array(
				'url'              => $url,
				'title_tag'        => get_the_title( $post ),
				'meta_description' => has_excerpt( $post ) ? get_the_excerpt( $post ) : '',
				'canonical'        => $url,
				'is_https'         => str_starts_with( strtolower( $url ), 'https://' ),
				'page_type'        => $post->post_type,
			)
		);
	}
}
