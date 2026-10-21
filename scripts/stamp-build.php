<?php
/**
 * Write the build stamp from the commit that is actually checked out.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
$git  = 'git -C ' . escapeshellarg( $root ) . ' rev-parse --short=7 HEAD';
$head = trim( (string) shell_exec( $git ) );
if ( 1 !== preg_match( '/\A[0-9a-f]{7}\z/', $head ) ) {
	fwrite( STDERR, "Could not read the checked-out commit.\n" );
	exit( 1 );
}

$contents = <<<PHP
<?php
/**
 * Generated build identity. WordPress reads this file and does not call Git.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

return array(
	'commit' => '{$head}',
);

PHP;

$path = $root . '/seo-studio-build.php';
if ( false === file_put_contents( $path, $contents ) ) {
	fwrite( STDERR, "Could not write the build stamp.\n" );
	exit( 1 );
}

fwrite( STDOUT, "Stamped build {$head}.\n" );
