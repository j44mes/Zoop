<?php
/**
 * Fail when the plugin version strings disagree or do not fit the score column.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

/**
 * Controlled release, development, and beta versions that fit plugin_version.
 */
function seostudio_version_valid( string $version ): bool {
	return strlen( $version ) <= 16 && 1 === preg_match( '/\A\d+\.\d+\.\d+(?:-(?:dev|beta)\.\d+)?\z/', $version );
}

/**
 * Compare the three version strings in this checkout.
 */
function seostudio_version_main(): int {
	$root     = dirname( __DIR__ );
	$plugin   = (string) file_get_contents( $root . '/seo-studio.php' );
	$readme   = (string) file_get_contents( $root . '/readme.txt' );
	$header   = '';
	$constant = '';
	$stable   = '';

	if ( preg_match( '/^\s*\*\s*Version:\s*(\S+)\s*$/m', $plugin, $found ) ) {
		$header = $found[1];
	}
	if ( preg_match( "/define\\(\\s*'SEOSTUDIO_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $plugin, $found ) ) {
		$constant = $found[1];
	}
	if ( preg_match( '/^Stable tag:\s*(\S+)\s*$/m', $readme, $found ) ) {
		$stable = $found[1];
	}

	$errors = array();
	if ( '' === $header || '' === $constant || '' === $stable ) {
		$errors[] = 'Could not read the plugin header, SEOSTUDIO_VERSION, and the readme stable tag.';
	}
	if ( $header !== $constant || $constant !== $stable ) {
		$errors[] = "Version strings differ: header '{$header}', constant '{$constant}', stable tag '{$stable}'.";
	}
	if ( ! seostudio_version_valid( $constant ) ) {
		$errors[] = "Version '{$constant}' must be X.Y.Z, X.Y.Z-dev.N, or X.Y.Z-beta.N, and at most 16 characters.";
	}

	if ( array() !== $errors ) {
		fwrite( STDERR, implode( "\n", $errors ) . "\n" );
		return 1;
	}

	fwrite( STDOUT, "Version {$constant} matches and fits plugin_version.\n" );
	return 0;
}

if ( isset( $argv[0] ) && 'validate-version.php' === basename( (string) $argv[0] ) ) {
	exit( seostudio_version_main() );
}
