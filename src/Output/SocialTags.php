<?php
/**
 * Open Graph and Twitter tags.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Output;

/**
 * Prints the social tags for one already-resolved page.
 */
final class SocialTags {
	/**
	 * Print the tags for one resolved page.
	 *
	 * @param array<string, mixed> $page
	 */
	public function render( array $page ): void {
		$type        = ! empty( $page['home'] ) ? 'website' : 'article';
		$image       = isset( $page['image'] ) && is_array( $page['image'] ) ? $page['image'] : array();
		$title       = $this->preferred( $page, 'social_title', 'title' );
		$description = $this->preferred( $page, 'social_description', 'description' );
		$tags        = array(
			'og:title'            => $title,
			'og:description'      => $description,
			'og:type'             => $type,
			'og:url'              => (string) ( $page['url'] ?? '' ),
			'twitter:card'        => '' !== (string) ( $image['url'] ?? '' ) ? 'summary_large_image' : 'summary',
			'twitter:title'       => $title,
			'twitter:description' => $description,
		);

		foreach ( $tags as $property => $content ) {
			if ( '' === $content ) {
				continue;
			}
			$attribute = str_starts_with( $property, 'twitter:' ) ? 'name' : 'property';
			printf(
				'<meta %1$s="%2$s" content="%3$s" />' . "\n",
				esc_attr( $attribute ),
				esc_attr( $property ),
				esc_attr( $content )
			);
		}

		if ( '' === (string) ( $image['url'] ?? '' ) ) {
			return;
		}

		printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( (string) $image['url'] ) );
		if ( ! empty( $image['width'] ) ) {
			printf( '<meta property="og:image:width" content="%d" />' . "\n", (int) $image['width'] );
		}
		if ( ! empty( $image['height'] ) ) {
			printf( '<meta property="og:image:height" content="%d" />' . "\n", (int) $image['height'] );
		}
		printf( '<meta name="twitter:image" content="%s" />' . "\n", esc_url( (string) $image['url'] ) );
	}

	/**
	 * Use the social override when one was saved, otherwise the SEO field.
	 *
	 * @param array<string, mixed> $page
	 */
	private function preferred( array $page, string $social, string $fallback ): string {
		$value = trim( (string) ( $page[ $social ] ?? '' ) );
		if ( '' !== $value ) {
			return $value;
		}

		return (string) ( $page[ $fallback ] ?? '' );
	}
}
