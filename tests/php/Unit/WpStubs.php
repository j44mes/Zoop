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

if ( ! function_exists( 'seostudio_run_hook' ) ) {
	/**
	 * Invoke unit-test hooks in priority order.
	 *
	 * @param callable(callable): void $invoke
	 */
	function seostudio_run_hook( string $hook, callable $invoke ): void {
		$groups = $GLOBALS['seostudio_hooks'][ $hook ] ?? array();
		if ( ! is_array( $groups ) ) {
			return;
		}

		ksort( $groups, SORT_NUMERIC );
		foreach ( $groups as $callbacks ) {
			if ( ! is_array( $callbacks ) ) {
				continue;
			}
			foreach ( $callbacks as $callback ) {
				if ( is_callable( $callback ) ) {
					$invoke( $callback );
				}
			}
		}
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	/**
	 * @param array<string, mixed> $args
	 * @return mixed
	 */
	function wp_remote_get( $url, $args = array() ) {
		$GLOBALS['seostudio_remote_calls'] = (int) ( $GLOBALS['seostudio_remote_calls'] ?? 0 ) + 1;
		if ( ! empty( $GLOBALS['seostudio_run_http_hooks'] ) ) {
			$handle = function_exists( 'curl_init' ) ? curl_init() : new stdClass();
			seostudio_run_hook(
				'http_api_curl',
				static function ( callable $callback ) use ( &$handle, $args, $url ): void {
					$callback( $handle, $args, $url );
				}
			);
			seostudio_run_hook(
				'requests-curl.before_send',
				static function ( callable $callback ) use ( &$handle ): void {
					$callback( $handle );
				}
			);
			$info = $GLOBALS['seostudio_curl_info'] ?? array( 'primary_ip' => '127.0.0.1' );
			seostudio_run_hook(
				'requests-curl.after_request',
				static function ( callable $callback ) use ( $info ): void {
					$callback( array(), $info );
				}
			);
			seostudio_run_hook(
				'requests-fsockopen.remote_socket',
				static function ( callable $callback ): void {
					$socket = (string) ( $GLOBALS['seostudio_remote_socket'] ?? 'tcp://localhost:80' );
					$callback( $socket );
					$GLOBALS['seostudio_remote_socket'] = $socket;
					$GLOBALS['seostudio_socket_log'][]  = $socket;
				}
			);
			$uri = (string) ( $GLOBALS['seostudio_remote_socket'] ?? '' );
			seostudio_run_hook(
				'requests-fsockopen.after_request',
				static function ( callable $callback ) use ( $uri ): void {
					$callback( array(), array( 'uri' => $uri ) );
				}
			);
		}
		unset( $url, $args );
		if ( isset( $GLOBALS['seostudio_remote_responses'] ) && is_array( $GLOBALS['seostudio_remote_responses'] ) && array() !== $GLOBALS['seostudio_remote_responses'] ) {
			return array_shift( $GLOBALS['seostudio_remote_responses'] );
		}

		return $GLOBALS['seostudio_remote_response'] ?? new WP_Error( 'http', 'failed' );
	}
}

if ( ! class_exists( 'WP_Hook' ) ) {
	/**
	 * Minimal stand-in for the WordPress hook registry.
	 */
	class WP_Hook {
		/**
		 * @var array<int, array<string, array{function: callable, accepted_args: int}>>
		 */
		public array $callbacks = array();
	}
}

if ( ! class_exists( 'WP_HTTP_Proxy' ) ) {
	/**
	 * Stand-in toggled by the local-observation tests.
	 */
	class WP_HTTP_Proxy {
		public function is_enabled(): bool {
			return ! empty( $GLOBALS['seostudio_proxy_enabled'] );
		}

		/**
		 * @param string $uri
		 */
		public function send_through_proxy( $uri ): bool {
			unset( $uri );
			return ! empty( $GLOBALS['seostudio_proxy_send'] );
		}
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

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * @param callable $callback
	 */
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		unset( $accepted_args );
		$GLOBALS['seostudio_hooks'][ $hook ][ $priority ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	/**
	 * @param callable $callback
	 */
	function remove_action( string $hook, $callback, int $priority = 10 ): bool {
		$hooks = $GLOBALS['seostudio_hooks'][ $hook ][ $priority ] ?? array();
		$GLOBALS['seostudio_hooks'][ $hook ][ $priority ] = array_values(
			array_filter(
				$hooks,
				static function ( $registered ) use ( $callback ): bool {
					return $registered !== $callback;
				}
			)
		);
		return true;
	}
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
		if ( 'home' === $name || 'siteurl' === $name ) {
			return $GLOBALS['seostudio_home'] ?? $default;
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
