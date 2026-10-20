<?php
/**
 * Choose the commit the scoring-ruleset guard compares against.
 *
 * Pull requests use the pull request base. An update to an existing branch
 * uses the commit that was there before the push. A newly created branch has
 * no previous tip, so the guard uses the parent of the checked-out commit.
 * A missing base fails closed.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

/**
 * Decide which commit is the ruleset baseline.
 *
 * @return array{sha: string, source: string, error: string}
 */
function seostudio_ruleset_base_decision( string $event, string $pr_base, string $before, string $parent ): array {
	if ( 'pull_request' === $event ) {
		if ( seostudio_ruleset_base_sha( $pr_base ) ) {
			return array(
				'sha'    => strtolower( $pr_base ),
				'source' => 'pull_request_base',
				'error'  => '',
			);
		}

		return array(
			'sha'    => '',
			'source' => '',
			'error'  => 'Pull request base is missing. The ruleset check does not guess.',
		);
	}

	if ( 'push' === $event ) {
		if ( seostudio_ruleset_base_sha( $before ) && ! seostudio_ruleset_base_zero( $before ) ) {
			return array(
				'sha'    => strtolower( $before ),
				'source' => 'push_before',
				'error'  => '',
			);
		}
		if ( seostudio_ruleset_base_sha( $parent ) ) {
			return array(
				'sha'    => strtolower( $parent ),
				'source' => 'new_branch_parent',
				'error'  => '',
			);
		}

		return array(
			'sha'    => '',
			'source' => '',
			'error'  => 'This push has no previous commit to compare. The ruleset check does not skip.',
		);
	}

	return array(
		'sha'    => '',
		'source' => '',
		'error'  => 'The ruleset check does not recognise this event.',
	);
}

/**
 * True when the value is a Git object name.
 */
function seostudio_ruleset_base_sha( string $value ): bool {
	return 1 === preg_match( '/\A[0-9a-f]{7,40}\z/i', $value );
}

/**
 * GitHub sets before to forty zeros when the branch is new.
 */
function seostudio_ruleset_base_zero( string $value ): bool {
	return 1 === preg_match( '/\A0{40}\z/', strtolower( $value ) );
}

/**
 * Read GitHub's event and print the baseline commit.
 */
function seostudio_ruleset_base_main(): int {
	$event  = getenv( 'GITHUB_EVENT_NAME' );
	$event  = is_string( $event ) ? $event : '';
	$base   = getenv( 'PR_BASE_SHA' );
	$base   = is_string( $base ) ? $base : '';
	$before = getenv( 'PUSH_BEFORE_SHA' );
	$before = is_string( $before ) ? $before : '';
	$parent = '';
	if ( 'push' === $event && ( '' === $before || seostudio_ruleset_base_zero( $before ) ) ) {
		$parent = trim( (string) shell_exec( 'git rev-parse --verify HEAD^ 2>/dev/null' ) );
	}

	$decision = seostudio_ruleset_base_decision( $event, $base, $before, $parent );
	if ( '' !== $decision['error'] ) {
		fwrite( STDERR, $decision['error'] . "\n" );
		return 1;
	}

	fwrite( STDOUT, $decision['sha'] . "\n" );
	return 0;
}

if ( isset( $argv[0] ) && 'resolve-ruleset-base.php' === basename( (string) $argv[0] ) ) {
	exit( seostudio_ruleset_base_main() );
}
