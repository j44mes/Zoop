<?php
/**
 * The stamp must be the checked-out commit, including a pull-request merge ref.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

$root   = dirname( __DIR__ );
$stamp_path = $root . '/seo-studio-build.php';
if ( ! is_readable( $stamp_path ) ) {
	fwrite( STDERR, "Build stamp is missing.\n" );
	exit( 1 );
}

$stamp = include $stamp_path;
$commit = is_array( $stamp ) ? (string) ( $stamp['commit'] ?? '' ) : '';
$git    = 'git -C ' . escapeshellarg( $root ) . ' rev-parse --short=7 HEAD';
$head   = trim( (string) shell_exec( $git ) );
if ( $commit !== $head || 1 !== preg_match( '/\A[0-9a-f]{7}\z/', $head ) ) {
	fwrite( STDERR, "Build stamp '{$commit}' does not match checked-out commit '{$head}'.\n" );
	exit( 1 );
}

require $root . '/vendor/autoload.php';

$plugin = (string) file_get_contents( $root . '/seo-studio.php' );
if ( 1 !== preg_match( "/define\\(\\s*'SEOSTUDIO_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $plugin, $found ) ) {
	fwrite( STDERR, "SEOSTUDIO_VERSION is missing.\n" );
	exit( 1 );
}

$version = $found[1];
$label   = SEOStudio\Core\VersionIdentity::labelFor( $version, $head );
$dev     = SEOStudio\Core\VersionIdentity::isDevelopment( $version );
if ( $dev && ! str_contains( $label, '(build ' . $head . ')' ) ) {
	fwrite( STDERR, "Identity label '{$label}' does not report this checkout.\n" );
	exit( 1 );
}
if ( ! $dev && 'Zoop ' . $version !== $label ) {
	fwrite( STDERR, "Release label '{$label}' must be the version alone.\n" );
	exit( 1 );
}

fwrite( STDOUT, $label . "\n" );
