<?php
/**
 * Renders a post into a PageModel without an HTTP request.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Content;

use SEOStudio\Core\Settings;
use SEOStudio\Integrations\ActiveHead;
use SEOStudio\Integrations\Detector;

/**
 * In-process content rendering for drafts and batch audits.
 */
final class Renderer {
	public function __construct(
		private readonly Extractor $extractor = new Extractor(),
		private readonly MetadataResolver $resolver = new MetadataResolver(),
		private readonly Detector $detector = new Detector(),
		private readonly ActiveHead $activeHead = new ActiveHead()
	) {}

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
	 * Render HTML with an already built analysis context.
	 *
	 * @param array<string, mixed> $context
	 */
	public function renderResolved( string $html, AnalysisContext $analysis, array $context = array() ): PageModel {
		$resolved                    = $this->resolver->resolve( $analysis );
		$context['metadata_status']  = 'estimated';
		$context['render_mode']      = (string) ( $context['render_mode'] ?? 'content' );
		$context['title_tag']        = $resolved['title_tag'];
		$context['meta_description'] = $resolved['meta_description'];
		$context['canonical']        = $resolved['canonical'];
		if ( 'content' === $context['render_mode'] ) {
			return $this->fromContent( $html, $context );
		}

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

		$url = get_permalink( $post );
		$url = is_string( $url ) ? $url : '';

		return $this->renderResolved(
			$this->captureContent( $post ),
			$this->contextFor( $post, $url ),
			array(
				'url'              => $url,
				'is_https'         => str_starts_with( strtolower( $url ), 'https://' ),
				'page_type'        => $post->post_type,
				'content_selector' => $this->selectorFor( $post ),
			)
		);
	}

	private function captureContent( \WP_Post $post ): string {
		$query = new \WP_Query(
			array(
				'p'              => $post->ID,
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

		return $html;
	}

	private function contextFor( \WP_Post $post, string $permalink ): AnalysisContext {
		$settings  = Settings::all();
		$separator = (string) ( $settings['site_title_separator'] ?? '–' );
		$category  = $this->categoryName( $post->ID );
		$values    = new TemplateValues(
			wp_strip_all_tags( get_the_title( $post ) ),
			wp_strip_all_tags( get_bloginfo( 'name' ) ),
			$separator,
			wp_strip_all_tags( has_excerpt( $post ) ? get_the_excerpt( $post ) : '' ),
			$category,
			wp_strip_all_tags( get_bloginfo( 'description' ) )
		);

		return new AnalysisContext(
			(string) ( $settings['mode'] ?? 'analysis' ),
			null !== $this->detector->first(),
			$values->title,
			$values->excerpt,
			$permalink,
			$values,
			$this->metaString( $post->ID, '_seostudio_title' ),
			$this->metaString( $post->ID, '_seostudio_description' ),
			$this->metaString( $post->ID, '_seostudio_canonical' ),
			$this->activeHead->forPost( $post->ID, $values, $permalink )
		);
	}

	public function selectorFor( \WP_Post $post ): string {
		$per_post = $this->metaString( $post->ID, '_seostudio_content_selector' );
		if ( '' !== $per_post ) {
			return $per_post;
		}

		$settings  = Settings::all();
		$selectors = apply_filters( 'seostudio/content_selectors', $settings['content_selectors'] ?? array() ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Published extension point.
		if ( ! is_array( $selectors ) ) {
			return '';
		}

		$selector = $selectors[ $post->post_type ] ?? '';
		return is_string( $selector ) ? $selector : '';
	}

	private function categoryName( int $postId ): string {
		$terms = get_the_category( $postId );
		if ( ! is_array( $terms ) || ! isset( $terms[0]->name ) ) {
			return '';
		}

		return (string) $terms[0]->name;
	}

	private function metaString( int $postId, string $key ): string {
		$value = get_post_meta( $postId, $key, true );
		return is_string( $value ) ? $value : '';
	}
}
