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

	/**
	 * Same public host and path prefix. Optional aliases are explicit host:port values.
	 *
	 * @param string[] $aliases
	 */
	public static function sameSite( string $identity, string $homeAbsolute, array $aliases = array() ): bool {
		$home = self::identity( self::absolute( $homeAbsolute, $homeAbsolute ) );
		if ( '' === $identity || '' === $home ) {
			return false;
		}
		$home_part = self::splitIdentity( $home );
		$page_part = self::splitIdentity( $identity );
		$hosts     = array( $home_part['host'] );
		foreach ( $aliases as $alias ) {
			$alias = strtolower( trim( (string) $alias ) );
			if ( '' !== $alias ) {
				$hosts[] = $alias;
			}
		}
		if ( ! in_array( $page_part['host'], $hosts, true ) ) {
			return false;
		}
		return self::pathWithin( $page_part['path'], $home_part['path'] );
	}

	/**
	 * Inventory identity for a trusted alias.
	 *
	 * Matching uses the public home host. The original path and query are preserved.
	 *
	 * @param string[] $aliases Explicit host:port aliases.
	 */
	public static function primaryIdentity( string $identity, string $homeAbsolute, array $aliases = array() ): string {
		if ( ! self::sameSite( $identity, $homeAbsolute, $aliases ) ) {
			return '';
		}
		$home = self::identity( self::absolute( $homeAbsolute, $homeAbsolute ) );
		if ( '' === $home ) {
			return '';
		}
		$home_part = self::splitIdentity( $home );
		$page_part = self::splitIdentity( $identity );
		$query     = '';
		$position  = strpos( $identity, '?' );
		if ( false !== $position ) {
			$query = substr( $identity, $position );
		}
		return $home_part['host'] . $page_part['path'] . $query;
	}

	/**
	 * Host of an untrusted www or non-www twin whose path is inside the public site.
	 *
	 * A name such as blog.example.com is not a twin of example.com. A different port is not a twin.
	 */
	public static function wwwTwinHost( string $identity, string $homeAbsolute ): string {
		$home = self::identity( self::absolute( $homeAbsolute, $homeAbsolute ) );
		if ( '' === $identity || '' === $home ) {
			return '';
		}
		$home_part = self::splitIdentity( $home );
		$page_part = self::splitIdentity( $identity );
		if ( ! self::pathWithin( $page_part['path'], $home_part['path'] ) ) {
			return '';
		}
		if ( $page_part['host'] === $home_part['host'] ) {
			return '';
		}
		$twin = self::twinHostPort( $home_part['host'] );
		return $page_part['host'] === $twin ? $page_part['host'] : '';
	}

	/**
	 * The other www form of a host, keeping an explicit port.
	 */
	private static function twinHostPort( string $hostPort ): string {
		$name = $hostPort;
		$port = '';
		$mark = strrpos( $hostPort, ':' );
		if ( false !== $mark && ctype_digit( substr( $hostPort, $mark + 1 ) ) ) {
			$name = substr( $hostPort, 0, $mark );
			$port = substr( $hostPort, $mark );
		}
		if ( str_starts_with( $name, 'www.' ) ) {
			$name = substr( $name, 4 );
		} else {
			$name = 'www.' . $name;
		}
		if ( '' === $name || ! str_contains( $name, '.' ) ) {
			return '';
		}
		return $name . $port;
	}

	/**
	 * Split a normalised identity into its host and path.
	 *
	 * @return array{host: string, path: string}
	 */
	private static function splitIdentity( string $identity ): array {
		$query = strpos( $identity, '?' );
		$path  = false === $query ? $identity : substr( $identity, 0, $query );
		$slash = strpos( $path, '/' );
		if ( false === $slash ) {
			return array(
				'host' => $path,
				'path' => '/',
			);
		}
		return array(
			'host' => substr( $path, 0, $slash ),
			'path' => substr( $path, $slash ),
		);
	}

	private static function pathWithin( string $path, string $homePath ): bool {
		$path     = '/' . trim( $path, '/' );
		$homePath = '/' . trim( $homePath, '/' );
		if ( '/' === $path ) {
			$path = '/';
		}
		if ( '/' === $homePath ) {
			return true;
		}
		return $path === $homePath || str_starts_with( $path, $homePath . '/' );
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
