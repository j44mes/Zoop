<?php
/**
 * Fingerprint of the inputs that can change published HTML.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Content;

use SEOStudio\Core\Settings;
use SEOStudio\Integrations\Detector;

/**
 * Compared with the hash stored at the last published-page check.
 */
final class SourceFingerprint {
	/**
	 * Hash the current post, publisher, and theme inputs. No HTTP request.
	 */
	public static function current( int $postId ): string {
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$settings = Settings::all();
		$plugin   = ( new Detector() )->first();
		$theme    = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
		$parts    = array(
			'title'            => (string) $post->post_title,
			'content'          => (string) $post->post_content,
			'excerpt'          => (string) $post->post_excerpt,
			'status'           => (string) $post->post_status,
			'password'         => (string) $post->post_password,
			'slug'             => (string) $post->post_name,
			'seo_title'        => self::meta( $postId, '_seostudio_title' ),
			'seo_description'  => self::meta( $postId, '_seostudio_description' ),
			'canonical'        => self::meta( $postId, '_seostudio_canonical' ),
			'robots'           => self::meta( $postId, '_seostudio_robots' ),
			'og_title'         => self::meta( $postId, '_seostudio_og_title' ),
			'og_description'   => self::meta( $postId, '_seostudio_og_description' ),
			'keyphrase'        => self::meta( $postId, '_seostudio_focus_keyphrase' ),
			'theme'            => $theme ? $theme->get_stylesheet() . '@' . (string) $theme->get( 'Version' ) : '',
			'publisher'        => is_array( $plugin ) ? $plugin['slug'] . '@' . $plugin['version'] : 'zoop',
			'mode'             => (string) ( $settings['mode'] ?? 'analysis' ),
			'blog_public'      => (string) get_option( 'blog_public' ),
			'default_robots'   => self::encode( $settings['default_robots'] ?? array() ),
			'home_title'       => (string) ( $settings['home_title'] ?? '' ),
			'home_description' => (string) ( $settings['home_description'] ?? '' ),
		);
		return md5( self::encode( $parts ) );
	}

	/**
	 * Meta keys whose changes can alter the published page.
	 *
	 * @return string[]
	 */
	public static function metaKeys(): array {
		return array(
			'_seostudio_title',
			'_seostudio_description',
			'_seostudio_canonical',
			'_seostudio_robots',
			'_seostudio_og_title',
			'_seostudio_og_description',
			'_seostudio_focus_keyphrase',
		);
	}

	private static function meta( int $postId, string $key ): string {
		$value = get_post_meta( $postId, $key, true );
		if ( is_array( $value ) ) {
			return self::encode( $value );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * JSON for a fingerprint. Unit tests do not load WordPress.
	 *
	 * @param mixed $value Value to encode.
	 */
	private static function encode( mixed $value ): string {
		if ( function_exists( 'wp_json_encode' ) ) {
			$encoded = wp_json_encode( $value );
			return is_string( $encoded ) ? $encoded : '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Used only when WordPress is not loaded.
		$encoded = json_encode( $value );
		return is_string( $encoded ) ? $encoded : '';
	}
}
