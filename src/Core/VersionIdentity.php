<?php
/**
 * Version line shown in the admin.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Core;

/**
 * Plugin version and the optional build stamp stay separate.
 *
 * A development or beta version with a real stamp includes the commit. A
 * stable version never does, and a missing stamp is left off rather than invented.
 */
final class VersionIdentity {
	public static function label(): string {
		$version = defined( 'SEOSTUDIO_VERSION' ) ? (string) SEOSTUDIO_VERSION : '';
		$build   = defined( 'SEOSTUDIO_BUILD' ) ? (string) SEOSTUDIO_BUILD : '';

		return self::labelFor( $version, $build );
	}

	public static function labelFor( string $version, string $build ): string {
		$version = trim( $version );
		$build   = strtolower( trim( $build ) );
		if ( self::isDevelopment( $version ) && 1 === preg_match( '/\A[0-9a-f]{7}\z/', $build ) ) {
			return sprintf( 'Zoop %s (build %s)', $version, $build );
		}

		return sprintf( 'Zoop %s', $version );
	}

	public static function isDevelopment( string $version ): bool {
		return 1 === preg_match( '/\A\d+\.\d+\.\d+-(?:dev|beta)\.\d+\z/', $version );
	}
}
