<?php
/**
 * Ruleset baseline selection for pull requests and pushes.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/scripts/resolve-ruleset-base.php';

/**
 * The guard must not ask GitHub to guess a pull request.
 */
final class RulesetBaseTest extends TestCase {
	public function testPullRequestUsesThePullRequestBase(): void {
		$decision = seostudio_ruleset_base_decision(
			'pull_request',
			'abcdef1234567890abcdef1234567890abcdef12',
			'0000000000000000000000000000000000000000',
			'1234567890abcdef1234567890abcdef12345678'
		);

		$this->assertSame( 'pull_request_base', $decision['source'] );
		$this->assertSame( 'abcdef1234567890abcdef1234567890abcdef12', $decision['sha'] );
		$this->assertSame( '', $decision['error'] );
	}

	public function testBranchPushUsesThePreviousTip(): void {
		$decision = seostudio_ruleset_base_decision(
			'push',
			'',
			'1234567890abcdef1234567890abcdef12345678',
			'abcdef1234567890abcdef1234567890abcdef12'
		);

		$this->assertSame( 'push_before', $decision['source'] );
		$this->assertSame( '1234567890abcdef1234567890abcdef12345678', $decision['sha'] );
	}

	public function testMainPushUsesThePreviousMainCommit(): void {
		$decision = seostudio_ruleset_base_decision(
			'push',
			'',
			'fedcba0987654321fedcba0987654321fedcba09',
			''
		);

		$this->assertSame( 'push_before', $decision['source'] );
		$this->assertSame( 'fedcba0987654321fedcba0987654321fedcba09', $decision['sha'] );
	}

	public function testNewBranchFallsBackToTheParentCommit(): void {
		$decision = seostudio_ruleset_base_decision(
			'push',
			'',
			'0000000000000000000000000000000000000000',
			'abcdef1234567890abcdef1234567890abcdef12'
		);

		$this->assertSame( 'new_branch_parent', $decision['source'] );
		$this->assertSame( 'abcdef1234567890abcdef1234567890abcdef12', $decision['sha'] );
	}

	public function testAPushWithNoHistoryFailsClosed(): void {
		$decision = seostudio_ruleset_base_decision( 'push', '', '0000000000000000000000000000000000000000', '' );

		$this->assertSame( '', $decision['sha'] );
		$this->assertStringContainsString( 'does not skip', $decision['error'] );
	}

	public function testAPullRequestWithoutABaseFailsClosed(): void {
		$decision = seostudio_ruleset_base_decision( 'pull_request', '', '1234567890abcdef1234567890abcdef12345678', 'abcdef1234567890abcdef1234567890abcdef12' );

		$this->assertSame( '', $decision['sha'] );
		$this->assertStringContainsString( 'does not guess', $decision['error'] );
	}
}
