<?php
/**
 * Fail when production scoring code changes without a higher ruleset.
 *
 * Pull requests compare with the pull request base. Pushes compare with the
 * previous tip, or the parent commit when the branch is new. A missing base
 * fails closed.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

/**
 * @param string[] $files
 */
function seostudio_ruleset_guard_error( int $base_version, int $head_version, array $files ): string {
	$scoring = array();
	foreach ( $files as $file ) {
		$file = str_replace( '\\', '/', $file );
		if ( 1 === preg_match( '#^src/Scoring/.+\.php$#', $file ) ) {
			$scoring[] = $file;
		}
	}
	if ( $head_version < $base_version ) {
		return "Scoring ruleset {$head_version} is lower than {$base_version}. Raising it is required; lowering it does not pass.";
	}
	if ( array() !== $scoring && $head_version <= $base_version ) {
		return 'Scoring files changed without incrementing Ruleset::CURRENT: ' . implode( ', ', $scoring );
	}

	return '';
}

/**
 * @param string[] $argv
 */
function seostudio_ruleset_guard_main( array $argv ): int {
	$base = $argv[1] ?? '';
	if ( '' === $base || ! preg_match( '/\A[0-9a-f]{7,40}\z/i', $base ) ) {
		fwrite( STDERR, "Pass the pull request base commit. A missing base does not skip the check.\n" );
		return 1;
	}
	$root = dirname( __DIR__ );
	$diff = shell_exec( 'git -C ' . escapeshellarg( $root ) . ' diff --name-only ' . escapeshellarg( $base ) . ' HEAD -- src/Scoring' );
	$files = array_values( array_filter( explode( "\n", (string) $diff ) ) );
	$base_version = seostudio_ruleset_guard_version( $root, $base );
	$head_version = seostudio_ruleset_guard_version( $root, 'HEAD' );
	$error = seostudio_ruleset_guard_error( $base_version, $head_version, $files );
	if ( '' !== $error ) {
		fwrite( STDERR, $error . "\n" );
		return 1;
	}
	fwrite( STDOUT, "Scoring ruleset {$head_version} is valid for this change.\n" );

	return 0;
}

function seostudio_ruleset_guard_version( string $root, string $revision ): int {
	$show = shell_exec( 'git -C ' . escapeshellarg( $root ) . ' show ' . escapeshellarg( $revision . ':src/Scoring/OnPage/Ruleset.php' ) . ' 2>/dev/null' );
	if ( ! is_string( $show ) || 1 !== preg_match( '/const\s+CURRENT\s*=\s*(\d+)\s*;/', $show, $found ) ) {
		return 0;
	}

	return (int) $found[1];
}

if ( isset( $argv[0] ) && 'check-ruleset-bump.php' === basename( (string) $argv[0] ) ) {
	exit( seostudio_ruleset_guard_main( $argv ) );
}
