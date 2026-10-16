<?php
/**
 * URL parsing that prefers WordPress when it is loaded.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Content;

/**
 * Shared parser so unit tests and WordPress agree on URL parts.
 */
final class Urls {
	/**
	 * Return the associative URL parts.
	 *
	 * @return array<string, mixed>|false
	 */
	public static function parts( string $url ): array|false {
		if ( function_exists( 'wp_parse_url' ) ) {
			$parsed = wp_parse_url( $url );
			return is_array( $parsed ) ? $parsed : false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The unit suite does not load WordPress.
		$parsed = parse_url( $url );
		return is_array( $parsed ) ? $parsed : false;
	}

	public static function component( string $url, int $component ): mixed {
		if ( function_exists( 'wp_parse_url' ) ) {
			return wp_parse_url( $url, $component );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- The unit suite does not load WordPress.
		return parse_url( $url, $component );
	}
}
