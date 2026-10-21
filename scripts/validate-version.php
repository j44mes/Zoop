<?php
/**
 * Fail when the plugin version strings disagree or do not fit the score column.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

$root    = dirname( __DIR__ );
$plugin  = (string) file_get_contents( $root . '/seo-studio.php' );
$readme  = (string) file_get_contents( $root . '/readme.txt' );
$header  = '';
$constant = '';
$stable  = '';

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

$version = $constant;
if ( strlen( $version ) > 16 ) {
	$errors[] = "Version '{$version}' is longer than the plugin_version column (16).";
}
if ( 1 !== preg_match( '/\A\d+\.\d+\.\d+(?:-dev\.\d+)?\z/', $version ) ) {
	$errors[] = "Version '{$version}' must be X.Y.Z or X.Y.Z-dev.N.";
}

if ( array() !== $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	exit( 1 );
}

fwrite( STDOUT, "Version {$version} matches and fits plugin_version.\n" );
