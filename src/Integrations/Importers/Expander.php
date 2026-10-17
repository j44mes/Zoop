<?php
/**
 * Expands the common title variables an importer understands.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Integrations\Importers;

use SEOStudio\Content\TemplateValues;
use SEOStudio\Core\Settings;

/**
 * Unknown tokens are left in place so an editor can review them.
 */
final class Expander {
	public function expand( int $postId, string $template ): string {
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post || '' === trim( $template ) ) {
			return $template;
		}

		$category = '';
		$terms    = get_the_category( $postId );
		if ( is_array( $terms ) && isset( $terms[0] ) && $terms[0] instanceof \WP_Term ) {
			$category = $terms[0]->name;
		}

		$settings = Settings::all();
		$values   = new TemplateValues(
			wp_strip_all_tags( $post->post_title ),
			wp_strip_all_tags( get_bloginfo( 'name' ) ),
			(string) ( $settings['site_title_separator'] ?? '–' ),
			wp_strip_all_tags( $post->post_excerpt ),
			$category,
			wp_strip_all_tags( get_bloginfo( 'description' ) )
		);

		return $values->expand( $template );
	}

	public function needsReview( string $expanded ): bool {
		return 1 === preg_match( '/%%[^%]+%%/', $expanded ) || 1 === preg_match( '/%[a-z0-9_]+%/', $expanded );
	}
}
