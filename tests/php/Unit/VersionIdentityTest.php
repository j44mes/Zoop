<?php
/**
 * Version and build display.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Core\VersionIdentity;
use SEOStudio\Scoring\OnPage\Ruleset;

/**
 * Development builds name the commit. Release builds do not.
 */
final class VersionIdentityTest extends TestCase {
	public function testDevelopmentStampIsShownAndReleaseStampIsNot(): void {
		$this->assertSame( 'Zoop 0.6.0-dev.1 (build abc1234)', VersionIdentity::labelFor( '0.6.0-dev.1', 'abc1234' ) );
		$this->assertSame( 'Zoop 0.6.0-dev.1', VersionIdentity::labelFor( '0.6.0-dev.1', '' ) );
		$this->assertSame( 'Zoop 0.6.0-dev.1', VersionIdentity::labelFor( '0.6.0-dev.1', 'not-a-sha' ) );
		$this->assertSame( 'Zoop 0.6.0', VersionIdentity::labelFor( '0.6.0', 'abc1234' ) );
		$this->assertSame( 'Zoop 0.6.0-beta.1 (build abc1234)', VersionIdentity::labelFor( '0.6.0-beta.1', 'abc1234' ) );
		$this->assertSame( 'Zoop 0.6.0-beta.1', VersionIdentity::labelFor( '0.6.0-beta.1', '' ) );
		$this->assertTrue( VersionIdentity::isDevelopment( '0.6.0-dev.1' ) );
		$this->assertTrue( VersionIdentity::isDevelopment( '0.6.0-beta.1' ) );
		$this->assertFalse( VersionIdentity::isDevelopment( '0.6.0' ) );
		$this->assertFalse( VersionIdentity::isDevelopment( '0.6.0-rc.1' ) );
		$this->assertTrue( Ruleset::matches( Ruleset::CURRENT ) );
		$this->assertFalse( Ruleset::matches( 0 ) );
		$this->assertFalse( Ruleset::matches( 10 ) );
		$this->assertFalse( Ruleset::matches( '1' ) );
		$this->assertFalse( Ruleset::matches( Ruleset::CURRENT + 1 ) );
	}
}
