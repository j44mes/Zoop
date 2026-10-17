<?php
/**
 * Global WordPress stand-ins used by the unit suite.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Stand-in for the WordPress error type.
	 */
	final class WP_Error {
		public function __construct( public string $code, public string $message ) {}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Stand-in for a WordPress post.
	 */
	final class WP_Post {
		public function __construct( public int $ID, public string $post_type, public string $post_content ) {}
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Stand-in that reports the posts flag from the test.
	 */
	final class WP_Query {
		/**
		 * @param array<string, mixed> $query
		 */
		public function __construct( public array $query = array() ) {}

		public function have_posts(): bool {
			return (bool) ( $GLOBALS['seostudio_have_posts'] ?? false );
		}

		public function the_post(): void {}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing
	 */
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * @param array<string, mixed> $args
	 * @return mixed
	 */
	function wp_remote_get( $url, $args = array() ) {
		unset( $url, $args );
		return $GLOBALS['seostudio_remote_response'] ?? new WP_Error( 'http', 'failed' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * @param array<string, mixed> $response
	 */
	function wp_remote_retrieve_response_code( $response ): int {
		return (int) ( $response['code'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	/**
	 * @param array<string, mixed> $response
	 */
	function wp_remote_retrieve_header( $response, string $header ): string {
		$headers = $response['headers'] ?? array();
		return is_array( $headers ) ? (string) ( $headers[ $header ] ?? '' ) : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * @param array<string, mixed> $response
	 */
	function wp_remote_retrieve_body( $response ): string {
		return (string) ( $response['body'] ?? '' );
	}
}

if ( ! function_exists( 'get_post' ) ) {
	/**
	 * @param int $post_id
	 * @return mixed
	 */
	function get_post( $post_id ) {
		if ( 0 === (int) $post_id ) {
			return null;
		}

		return $GLOBALS['seostudio_post'] ?? null;
	}
}

if ( ! function_exists( 'get_permalink' ) ) {
	/**
	 * @param mixed $post
	 * @return mixed
	 */
	function get_permalink( $post ) {
		unset( $post );
		return $GLOBALS['seostudio_permalink'] ?? '';
	}
}

if ( ! function_exists( 'wp_reset_postdata' ) ) {
	function wp_reset_postdata(): void {}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param mixed $default
	 * @return mixed
	 */
	function get_option( string $name, $default = false ) {
		if ( 'seostudio_settings' === $name ) {
			return $GLOBALS['seostudio_settings'] ?? $default;
		}

		return $default;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text ): string {
		return trim( strip_tags( $text ) );
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	/**
	 * @param mixed $post
	 */
	function get_the_title( $post ): string {
		unset( $post );
		return 'Hello';
	}
}

if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string {
		return 'description' === $show ? 'Tagline' : 'Example';
	}
}

if ( ! function_exists( 'has_excerpt' ) ) {
	/**
	 * @param mixed $post
	 */
	function has_excerpt( $post ): bool {
		unset( $post );
		return (bool) ( $GLOBALS['seostudio_has_excerpt'] ?? false );
	}
}

if ( ! function_exists( 'get_the_excerpt' ) ) {
	/**
	 * @param mixed $post
	 */
	function get_the_excerpt( $post ): string {
		unset( $post );
		return (string) ( $GLOBALS['seostudio_excerpt'] ?? '' );
	}
}

if ( ! function_exists( 'get_the_category' ) ) {
	/**
	 * @param int $post_id
	 * @return mixed
	 */
	function get_the_category( $post_id ) {
		unset( $post_id );
		return $GLOBALS['seostudio_categories'] ?? array();
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	/**
	 * @param int    $post_id
	 * @param string $key
	 * @param bool   $single
	 * @return mixed
	 */
	function get_post_meta( $post_id, $key, $single = false ) {
		unset( $single );
		return $GLOBALS['seostudio_meta'][ (int) $post_id ][ $key ] ?? '';
	}
}
