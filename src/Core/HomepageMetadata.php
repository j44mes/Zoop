<?php
/**
 * Authoritative homepage settings shared by scoring and head output.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Core;

use SEOStudio\Content\TemplateValues;

/**
 * Homepage templates use site values, including when a static page supplies the body.
 */
final class HomepageMetadata {
	public static function isStaticPage( int $postId ): bool {
		return $postId > 0 && 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === $postId;
	}

	/**
	 * Empty homepage settings fall back to the site name and tagline.
	 *
	 * @return array{title_tag: string, meta_description: string, canonical: string}
	 */
	public static function resolve(): array {
		$settings    = Settings::all();
		$name        = wp_strip_all_tags( get_bloginfo( 'name' ) );
		$tagline     = wp_strip_all_tags( get_bloginfo( 'description' ) );
		$values      = new TemplateValues( $name, $name, (string) ( $settings['site_title_separator'] ?? '–' ), '', '', $tagline );
		$title       = $values->expand( (string) ( $settings['home_title'] ?? '' ) );
		$description = $values->expand( (string) ( $settings['home_description'] ?? '' ) );

		return array(
			'title_tag'        => '' === trim( $title ) ? $name : trim( $title ),
			'meta_description' => '' === trim( $description ) ? $tagline : trim( $description ),
			'canonical'        => (string) home_url( '/' ),
		);
	}
}
