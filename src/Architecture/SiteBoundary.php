<?php
/**
 * Public website boundary for internal links.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Architecture;

use SEOStudio\Core\Settings;
use SEOStudio\Content\Urls;

/**
 * The public site is home_url(), including its path. The WordPress directory is not the boundary.
 */
final class SiteBoundary {
	/**
	 * Host and port pairs an administrator has trusted, besides the public home host.
	 *
	 * @return string[]
	 */
	public static function aliases(): array {
		if ( ! class_exists( Settings::class ) || ! method_exists( Settings::class, 'all' ) ) {
			return array();
		}
		$settings = Settings::all();
		$aliases  = $settings['host_aliases'] ?? array();
		return is_array( $aliases ) ? array_map( 'strval', $aliases ) : array();
	}

	/**
	 * Whether an absolute URL is inside the public site or a trusted alias.
	 *
	 * A canonical hostname is not accepted here. Only home_url() and explicit aliases count.
	 */
	public static function contains( string $absolute, string $home ): bool {
		$identity = UrlKey::identity( UrlKey::absolute( $absolute, $absolute ) );
		if ( '' === $identity ) {
			return false;
		}
		return UrlKey::sameSite( $identity, $home, self::aliasHosts( $home ) );
	}

	/**
	 * Alias hosts, with the public home port applied when the alias omits one.
	 *
	 * @return string[]
	 */
	public static function aliasHosts( string $home ): array {
		$home_identity = UrlKey::identity( UrlKey::absolute( $home, $home ) );
		$home_host     = self::hostPort( $home_identity );
		$hosts         = array();
		foreach ( self::aliases() as $alias ) {
			$alias = strtolower( trim( $alias ) );
			if ( '' === $alias || str_contains( $alias, '/' ) ) {
				continue;
			}
			if ( ! str_contains( $alias, ':' ) && '' !== $home_host && str_contains( $home_host, ':' ) ) {
				$port   = substr( $home_host, (int) strrpos( $home_host, ':' ) );
				$alias .= $port;
			}
			if ( $alias !== $home_host ) {
				$hosts[] = $alias;
			}
		}
		return $hosts;
	}

	public static function hostPort( string $identity ): string {
		$query = strpos( $identity, '?' );
		$path  = false === $query ? $identity : substr( $identity, 0, $query );
		$slash = strpos( $path, '/' );
		return false === $slash ? $path : substr( $path, 0, $slash );
	}

	/**
	 * Public path prefix from a home URL. The installation directory is not used.
	 */
	public static function publicPath( string $home ): string {
		$path = Urls::component( $home, PHP_URL_PATH );
		$path = is_string( $path ) && '' !== $path ? $path : '/';
		$trim = '/' . trim( $path, '/' );
		return '/' === $trim ? '/' : rtrim( $trim, '/' );
	}
}
