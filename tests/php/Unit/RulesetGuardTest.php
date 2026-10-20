<?php
/**
 * The scoring-ruleset CI guard.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/scripts/check-ruleset-bump.php';

/**
 * Positive and negative decisions, including a merge commit.
 */
final class RulesetGuardTest extends TestCase {
	public function testDecisions(): void {
		$this->assertSame( '', seostudio_ruleset_guard_error( 1, 1, array( 'src/Content/Extractor.php' ) ) );
		$this->assertSame( '', seostudio_ruleset_guard_error( 0, 1, array( 'src/Scoring/OnPage/Ruleset.php' ) ) );
		$this->assertSame( '', seostudio_ruleset_guard_error( 1, 2, array( 'src/Scoring/OnPage/Scorer.php' ) ) );
		$this->assertNotSame( '', seostudio_ruleset_guard_error( 1, 1, array( 'src/Scoring/OnPage/Scorer.php' ) ) );
		$this->assertNotSame( '', seostudio_ruleset_guard_error( 2, 1, array() ) );
		$this->assertStringContainsString( 'lower', seostudio_ruleset_guard_error( 2, 1, array( 'src/Scoring/OnPage/Ruleset.php' ) ) );
	}

	public function testAMergeCommitStillSeesTheBranchScoringChange(): void {
		$root = sys_get_temp_dir() . '/zoop-ruleset-guard-' . bin2hex( random_bytes( 4 ) );
		mkdir( $root . '/src/Scoring/OnPage', 0777, true );
		$this->git( $root, 'init -b basebranch' );
		$this->git( $root, 'config user.email test@example.test' );
		$this->git( $root, 'config user.name Test' );
		file_put_contents( $root . '/src/Scoring/OnPage/Ruleset.php', "<?php\nclass Ruleset { public const CURRENT = 1; }\n" );
		file_put_contents( $root . '/src/Scoring/OnPage/Scorer.php', "<?php\nclass Scorer {}\n" );
		$this->git( $root, 'add src' );
		$this->git( $root, 'commit -m base' );
		$base = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . ' rev-parse HEAD' ) );
		$this->git( $root, 'checkout -b feature' );
		file_put_contents( $root . '/src/Scoring/OnPage/Scorer.php', "<?php\nclass Scorer { public function score(): int { return 1; } }\n" );
		$this->git( $root, 'add src' );
		$this->git( $root, 'commit -m change' );
		$this->git( $root, 'checkout basebranch' );
		$this->git( $root, 'merge --no-ff feature -m merge' );
		$script = dirname( __DIR__, 3 ) . '/scripts/check-ruleset-bump.php';
		$command = 'git -C ' . escapeshellarg( $root ) . ' diff --name-only ' . escapeshellarg( $base ) . ' HEAD -- src/Scoring';
		$files   = array_values( array_filter( explode( "\n", (string) shell_exec( $command ) ) ) );
		$this->assertContains( 'src/Scoring/OnPage/Scorer.php', $files );
		$this->assertNotSame( '', seostudio_ruleset_guard_error( 1, 1, $files ) );
		file_put_contents( $root . '/src/Scoring/OnPage/Ruleset.php', "<?php\nclass Ruleset { public const CURRENT = 2; }\n" );
		$this->git( $root, 'add src' );
		$this->git( $root, 'commit -m bump' );
		$files = array_values( array_filter( explode( "\n", (string) shell_exec( $command ) ) ) );
		$this->assertSame( '', seostudio_ruleset_guard_error( 1, 2, $files ) );
		unset( $script );
	}

	public function testAWellFormedUnknownShaFailsClosed(): void {
		$root = $this->repository();
		$error = seostudio_ruleset_guard_check( $root, 'deadbee' );

		$this->assertStringContainsString( 'not in this repository', $error );
		$this->assertStringNotContainsString( 'is valid', $error );
		$this->assertSame( 1, seostudio_ruleset_guard_main( array( 'check-ruleset-bump.php', 'deadbee' ), $root ) );
	}

	public function testAMissingBaseObjectFailsClosed(): void {
		$root  = $this->repository();
		$error = seostudio_ruleset_guard_check( $root, str_repeat( 'f', 40 ) );

		$this->assertStringContainsString( 'missing commit', $error );
		$this->assertStringNotContainsString( 'is valid', $error );
	}

	public function testAFailedGitCommandFailsClosed(): void {
		$root = sys_get_temp_dir() . '/zoop-not-a-repo-' . bin2hex( random_bytes( 4 ) );
		mkdir( $root );
		$error = seostudio_ruleset_guard_check( $root, 'deadbee' );

		$this->assertStringContainsString( 'failed Git command', $error );
		$this->assertStringNotContainsString( 'is valid', $error );
		$this->assertSame( 1, seostudio_ruleset_guard_main( array( 'check-ruleset-bump.php', 'deadbee' ), $root ) );
	}

	public function testAResolvedBaselineWithNoScoringChangePasses(): void {
		$root = $this->repository();
		$base = trim( (string) shell_exec( 'git -C ' . escapeshellarg( $root ) . ' rev-parse HEAD' ) );

		$this->assertSame( '', seostudio_ruleset_guard_check( $root, $base ) );
	}

	private function repository(): string {
		$root = sys_get_temp_dir() . '/zoop-ruleset-base-' . bin2hex( random_bytes( 4 ) );
		mkdir( $root . '/src/Scoring/OnPage', 0777, true );
		$this->git( $root, 'init -b main' );
		$this->git( $root, 'config user.email test@example.test' );
		$this->git( $root, 'config user.name Test' );
		file_put_contents( $root . '/src/Scoring/OnPage/Ruleset.php', "<?php\nclass Ruleset { public const CURRENT = 1; }\n" );
		$this->git( $root, 'add src' );
		$this->git( $root, 'commit -m base' );

		return $root;
	}

	private function git( string $root, string $args ): void {
		shell_exec( 'git -C ' . escapeshellarg( $root ) . ' ' . $args . ' 2>&1' );
	}
}
