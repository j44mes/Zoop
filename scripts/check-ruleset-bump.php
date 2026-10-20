<?php
/**
 * Fail when production scoring code changes without a higher ruleset.
 *
 * Pull requests compare with the pull request base. Pushes compare with the
 * previous tip, or the parent commit when the branch is new. A missing base,
 * a failed diff, or an unreadable ruleset fails closed.
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
 * Compare one checkout with a baseline commit. An empty string means the change is allowed.
 */
function seostudio_ruleset_guard_check( string $root, string $base ): string {
	if ( '' === $base || 1 !== preg_match( '/\A[0-9a-f]{7,40}\z/i', $base ) ) {
		return 'Pass the baseline commit. A missing base does not skip the check.';
	}

	$base_commit = seostudio_ruleset_guard_commit( $root, $base );
	if ( '' !== $base_commit['error'] ) {
		return $base_commit['error'];
	}
	$head_commit = seostudio_ruleset_guard_commit( $root, 'HEAD' );
	if ( '' !== $head_commit['error'] ) {
		return $head_commit['error'];
	}

	$diff = seostudio_ruleset_guard_git( $root, array( 'diff', '--name-only', $base_commit['sha'], $head_commit['sha'], '--', 'src/Scoring' ) );
	if ( 0 !== $diff['code'] ) {
		return 'Could not list scoring changes. A failed Git command is not an empty change.';
	}

	$base_version = seostudio_ruleset_guard_version( $root, $base_commit['sha'] );
	if ( '' !== $base_version['error'] ) {
		return $base_version['error'];
	}
	$head_version = seostudio_ruleset_guard_version( $root, $head_commit['sha'] );
	if ( '' !== $head_version['error'] ) {
		return $head_version['error'];
	}

	$files = array_values( array_filter( explode( "\n", $diff['stdout'] ), static fn ( string $file ): bool => '' !== $file ) );

	return seostudio_ruleset_guard_error( $base_version['version'], $head_version['version'], $files );
}

/**
 * @param string[] $argv
 */
function seostudio_ruleset_guard_main( array $argv, ?string $root = null ): int {
	$root  = $root ?? dirname( __DIR__ );
	$error = seostudio_ruleset_guard_check( $root, $argv[1] ?? '' );
	if ( '' !== $error ) {
		fwrite( STDERR, $error . "\n" );
		return 1;
	}

	$head = seostudio_ruleset_guard_version( $root, 'HEAD' );
	fwrite( STDOUT, 'Scoring ruleset ' . $head['version'] . " is valid for this change.\n" );

	return 0;
}

/**
 * Resolve a revision to a commit, or explain why it cannot be used.
 *
 * @return array{sha: string, error: string}
 */
function seostudio_ruleset_guard_commit( string $root, string $revision ): array {
	$parsed = seostudio_ruleset_guard_git( $root, array( 'rev-parse', '--verify', '--quiet', $revision . '^{commit}' ) );
	$sha    = trim( $parsed['stdout'] );
	if ( 0 !== $parsed['code'] || 1 !== preg_match( '/\A[0-9a-f]{40}\z/', $sha ) ) {
		$failed = str_contains( $parsed['stderr'], 'not a git repository' ) || str_contains( $parsed['stderr'], 'could not be started' );
		return array(
			'sha'   => '',
			'error' => $failed
				? 'Could not list scoring changes. A failed Git command is not an empty change.'
				: "Baseline commit {$revision} is not in this repository. A missing commit is not an empty scoring change.",
		);
	}

	return array(
		'sha'   => $sha,
		'error' => '',
	);
}

/**
 * Read Ruleset::CURRENT at a commit that has already been resolved.
 *
 * A scoring file that does not exist yet is ruleset 0. An unreadable file is a failure.
 *
 * @return array{version: int, error: string}
 */
function seostudio_ruleset_guard_version( string $root, string $commit ): array {
	$show = seostudio_ruleset_guard_git( $root, array( 'show', $commit . ':src/Scoring/OnPage/Ruleset.php' ) );
	if ( 0 !== $show['code'] ) {
		$missing = str_contains( $show['stderr'], 'does not exist' ) || str_contains( $show['stderr'], 'not in' );
		if ( $missing ) {
			return array(
				'version' => 0,
				'error'   => '',
			);
		}

		return array(
			'version' => 0,
			'error'   => "Could not read the scoring ruleset at {$commit}. A failed Git command is not ruleset 0.",
		);
	}
	if ( 1 !== preg_match( '/const\s+CURRENT\s*=\s*(\d+)\s*;/', $show['stdout'], $found ) ) {
		return array(
			'version' => 0,
			'error'   => "Could not read Ruleset::CURRENT at {$commit}.",
		);
	}

	return array(
		'version' => (int) $found[1],
		'error'   => '',
	);
}

/**
 * Run git without a shell. A command that cannot start is a failure.
 *
 * @param string[] $args
 * @return array{code: int, stdout: string, stderr: string}
 */
function seostudio_ruleset_guard_git( string $root, array $args ): array {
	$command = array_merge( array( 'git', '-C', $root ), $args );
	$pipes   = array();
	$process = proc_open(
		$command,
		array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes
	);
	if ( ! is_resource( $process ) ) {
		return array(
			'code'   => 1,
			'stdout' => '',
			'stderr' => 'git could not be started',
		);
	}

	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	fclose( $pipes[1] );
	fclose( $pipes[2] );

	return array(
		'code'   => proc_close( $process ),
		'stdout' => is_string( $stdout ) ? $stdout : '',
		'stderr' => is_string( $stderr ) ? $stderr : '',
	);
}

if ( isset( $argv[0] ) && 'check-ruleset-bump.php' === basename( (string) $argv[0] ) ) {
	exit( seostudio_ruleset_guard_main( $argv ) );
}
