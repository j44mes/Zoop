<?php
/**
 * Fail when a source directory is under the line-coverage floor.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

if ( $argc < 4 ) {
	fwrite( STDERR, "Usage: php scripts/coverage-floor.php <clover.xml> <percent> <directory>...\n" );
	exit( 1 );
}

$clover = simplexml_load_file( $argv[1] );
if ( false === $clover ) {
	fwrite( STDERR, "Could not read {$argv[1]}\n" );
	exit( 1 );
}

$floor = (float) $argv[2];
$dirs  = array_slice( $argv, 3 );
$hits  = array_fill_keys( $dirs, 0 );
$total = array_fill_keys( $dirs, 0 );

foreach ( $clover->project->file as $file ) {
	$relative = str_replace( '\\', '/', (string) $file['name'] );
	$matched  = '';
	foreach ( $dirs as $dir ) {
		if ( str_contains( $relative, '/' . trim( $dir, '/' ) . '/' ) ) {
			$matched = $dir;
			break;
		}
	}
	if ( '' === $matched ) {
		continue;
	}

	foreach ( $file->line as $line ) {
		if ( 'stmt' !== (string) $line['type'] ) {
			continue;
		}
		++$total[ $matched ];
		if ( (int) $line['count'] > 0 ) {
			++$hits[ $matched ];
		}
	}
}

$failed = false;
foreach ( $dirs as $dir ) {
	$percent = 0 === $total[ $dir ] ? 0 : ( 100 * $hits[ $dir ] / $total[ $dir ] );
	echo $dir . ' ' . number_format( $percent, 2 ) . "% ({$hits[$dir]}/{$total[$dir]})\n";
	if ( $percent < $floor ) {
		$failed = true;
	}
}

exit( $failed ? 1 : 0 );
