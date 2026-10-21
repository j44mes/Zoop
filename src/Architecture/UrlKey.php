<?php
/**
 * Stable internal addresses for the link graph.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Architecture;

use SEOStudio\Content\Urls;

/**
 * Resolves a link against a page and ignores the scheme when comparing this site.
 */
final class UrlKey {
	/**
	 * Absolute http(s) URL, or an empty string when the address cannot be followed.
	 */
	public static function absolute( string $href, string $base ): string {
		$href = trim( $href );
		if ( '' === $href || str_starts_with( $href, '#' ) ) {
			return '';
		}
		if ( preg_match( '#^(mailto|tel|javascript|data):#i', $href ) ) {
			return '';
		}

		$hash = strpos( $href, '#' );
		if ( false !== $hash ) {
			$href = substr( $href, 0, $hash );
		}
		if ( '' === $href ) {
			return '';
		}

		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $href ) ) {
			$absolute = $href;
		} elseif ( str_starts_with( $href, '//' ) ) {
			$scheme = Urls::component( $base, PHP_URL_SCHEME );
			if ( ! is_string( $scheme ) || '' === $scheme ) {
				return '';
			}
			$absolute = $scheme . ':' . $href;
		} else {
			$parts = Urls::parts( $base );
			if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				return '';
			}
			$origin = strtolower( (string) $parts['scheme'] ) . '://' . $parts['host'];
			if ( isset( $parts['port'] ) ) {
				$origin .= ':' . (int) $parts['port'];
			}
			if ( str_starts_with( $href, '/' ) ) {
				$absolute = $origin . $href;
			} else {
				$path = (string) ( $parts['path'] ?? '/' );
				if ( ! str_ends_with( $path, '/' ) ) {
					$trimmed = preg_replace( '#/[^/]*$#', '/', $path );
					$path    = is_string( $trimmed ) && '' !== $trimmed ? $trimmed : '/';
				}
				$absolute = $origin . $path . $href;
			}
		}

		return self::canonical( $absolute );
	}

	/**
	 * Host, port, path, and query. The scheme is omitted so http and https match.
	 */
	public static function identity( string $absolute ): string {
		$parts = Urls::parts( $absolute );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$host = strtolower( (string) $parts['host'] );
		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path = self::path( (string) ( $parts['path'] ?? '/' ) );
		return $host . $port . $path . self::query( (string) ( $parts['query'] ?? '' ) );
	}

	public static function sameSite( string $identity, string $homeAbsolute ): bool {
		$home = self::identity( self::absolute( $homeAbsolute, $homeAbsolute ) );
		if ( '' === $identity || '' === $home ) {
			return false;
		}
		$home_host = preg_replace( '#/.*$#', '', $home );
		$page_host = preg_replace( '#/.*$#', '', $identity );
		return is_string( $home_host ) && $home_host === $page_host;
	}

	/**
	 * WordPress system addresses are not missing content pages.
	 */
	public static function systemPath( string $absolute ): bool {
		$path = Urls::component( $absolute, PHP_URL_PATH );
		$path = strtolower( is_string( $path ) ? $path : '' );
		if ( '' === $path ) {
			return false;
		}
		if ( str_contains( $path, '/wp-admin' ) || str_contains( $path, '/wp-login.php' ) || str_contains( $path, '/wp-json' ) || str_contains( $path, '/xmlrpc.php' ) ) {
			return true;
		}
		$trimmed = rtrim( $path, '/' );
		return '/feed' === $trimmed || str_ends_with( $trimmed, '/feed' );
	}

	private static function canonical( string $absolute ): string {
		$parts = Urls::parts( $absolute );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
			return '';
		}
		$host      = strtolower( (string) $parts['host'] );
		$port      = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$omit      = ( 'https' === $scheme && 443 === $port ) || ( 'http' === $scheme && 80 === $port );
		$port_part = ( $port > 0 && ! $omit ) ? ':' . $port : '';
		$path      = self::path( (string) ( $parts['path'] ?? '/' ) );
		return $scheme . '://' . $host . $port_part . $path . self::query( (string) ( $parts['query'] ?? '' ) );
	}

	private static function path( string $path ): string {
		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}
		$joined = '/' . implode( '/', $segments );
		return '/' === $joined ? '/' : rtrim( $joined, '/' );
	}

	/**
	 * Drop campaign parameters so a tracking suffix is the same page.
	 */
	private static function query( string $query ): string {
		if ( '' === $query ) {
			return '';
		}
		$pairs = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$bits = explode( '=', $pair, 2 );
			$name = rawurldecode( $bits[0] );
			if ( '' === $name || str_starts_with( strtolower( $name ), 'utm_' ) ) {
				continue;
			}
			$pairs[ $name ] = $bits[1] ?? '';
		}
		if ( array() === $pairs ) {
			return '';
		}
		ksort( $pairs );
		$encoded = array();
		foreach ( $pairs as $name => $value ) {
			$encoded[] = rawurlencode( (string) $name ) . '=' . rawurlencode( rawurldecode( (string) $value ) );
		}
		return '?' . implode( '&', $encoded );
	}
}
