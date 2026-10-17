<?php
/**
 * Document title for Owner mode.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Output;

use SEOStudio\Content\TemplateValues;
use SEOStudio\Core\Settings;
use SEOStudio\Core\HomepageMetadata;

/**
 * Expands the home title or the post SEO title. An empty template leaves the theme title alone.
 */
final class TitleFilter {
	public function filter( string $title ): string {
		if ( is_front_page() ) {
			return HomepageMetadata::resolve()['title_tag'];
		}

		if ( ! is_singular() ) {
			return $title;
		}

		$post_id  = (int) get_queried_object_id();
		$template = get_post_meta( $post_id, '_seostudio_title', true );
		if ( ! is_string( $template ) || '' === trim( $template ) ) {
			return $title;
		}

		$expanded = $this->expand( $post_id, $template );
		return '' === $expanded ? $title : $expanded;
	}

	public function expand( int $postId, string $template ): string {
		if ( '' === trim( $template ) ) {
			return '';
		}

		$post     = $postId > 0 ? get_post( $postId ) : null;
		$settings = Settings::all();
		$category = '';
		if ( $postId > 0 ) {
			$terms = get_the_category( $postId );
			if ( is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof \WP_Term ) {
				$category = $terms[0]->name;
			}
		}

		$values = new TemplateValues(
			$post instanceof \WP_Post ? wp_strip_all_tags( $post->post_title ) : wp_strip_all_tags( get_bloginfo( 'name' ) ),
			wp_strip_all_tags( get_bloginfo( 'name' ) ),
			(string) ( $settings['site_title_separator'] ?? '–' ),
			$post instanceof \WP_Post ? wp_strip_all_tags( $post->post_excerpt ) : '',
			$category,
			wp_strip_all_tags( get_bloginfo( 'description' ) )
		);

		return $values->expand( $template );
	}
}
