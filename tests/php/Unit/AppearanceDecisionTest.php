<?php
/**
 * Search-appearance proposal decisions.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Appearance\Decision;

/**
 * A proposal is not a published change, and Apply exists only for Zoop.
 */
final class AppearanceDecisionTest extends TestCase {
	public function testAProposalDoesNotChangeThePublishedValues(): void {
		$decision = new Decision();
		$clean    = $decision->proposal( 'Fire door installation', 'Certified installation and inspections.' );

		$this->assertTrue( $clean['ok'] );
		$this->assertSame( 'Fire door installation', $clean['title'] );
		$this->assertSame( 'Certified installation and inspections.', $clean['description'] );
		$this->assertFalse( $decision->canApply( false ) );
		$blocked = $decision->apply( false, true, 'Fire Doors', 'Old description', $clean['title'], $clean['description'], 'Fire Doors', 'Old description' );
		$this->assertSame( 'unsupported', $blocked['status'] );
		$this->assertSame( 'Fire Doors', $blocked['title'] );
	}

	public function testApplyRequiresConfirmationAndKeepsThePreviousValues(): void {
		$decision = new Decision();
		$waiting  = $decision->apply( true, false, 'Fire Doors', 'Old description', 'Fire door installation', 'New description', 'Fire Doors', 'Old description' );
		$this->assertSame( 'unconfirmed', $waiting['status'] );
		$this->assertSame( 'Fire Doors', $waiting['title'] );

		$stale = $decision->apply( true, true, 'Changed title', 'Old description', 'Fire door installation', 'New description', 'Fire Doors', 'Old description' );
		$this->assertSame( 'stale', $stale['status'] );
		$this->assertSame( 'Changed title', $stale['title'] );

		$applied = $decision->apply( true, true, 'Fire Doors', 'Old description', 'Fire door installation', 'New description', 'Fire Doors', 'Old description' );
		$this->assertSame( 'applied', $applied['status'] );
		$this->assertSame( 'Fire door installation', $applied['title'] );
		$this->assertSame( 'New description', $applied['description'] );
		$this->assertSame( 'Fire Doors', $applied['previous_title'] );
		$this->assertSame( 'Old description', $applied['previous_description'] );
		$this->assertTrue( $decision->canApply( true ) );
	}

	public function testAFailedLiveCheckDoesNotClaimTheHtmlChanged(): void {
		$decision = new Decision();
		$missed   = $decision->verification( false, false );
		$this->assertSame( 'not_verified', $missed['status'] );
		$this->assertFalse( $missed['html_matches'] );

		$different = $decision->verification( true, $decision->htmlMatches( 'Fire door installation', 'Fire Doors', 'New description', 'Old description' ) );
		$this->assertSame( 'not_verified', $different['status'] );
		$this->assertFalse( $decision->htmlMatches( 'Fire door installation', 'Fire door installation', '', 'A description is still published.' ) );
		$this->assertTrue( $decision->htmlMatches( 'Fire door installation', 'Fire door installation', '', '' ) );
	}

	public function testRestoreReturnsThePreviousValues(): void {
		$decision = new Decision();
		$restored = $decision->restore( 'Fire Doors', 'Old description' );
		$this->assertSame( 'Fire Doors', $restored['title'] );
		$this->assertSame( 'Old description', $restored['description'] );

		$matched = $decision->verification( true, $decision->htmlMatches( 'Fire door installation', 'Fire door installation', 'New description', 'New description' ) );
		$this->assertSame( 'verified', $matched['status'] );
		$this->assertSame( 'live_html', $matched['basis'] );
		$this->assertTrue( $matched['html_matches'] );
		$unread = $decision->verification( false, true );
		$this->assertSame( 'not_observed', $unread['basis'] );
		$this->assertFalse( $unread['html_matches'] );
	}
}
