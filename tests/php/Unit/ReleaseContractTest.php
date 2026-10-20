<?php
/**
 * Beta version contract and release package allowlist.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/scripts/validate-version.php';
require_once dirname( __DIR__, 3 ) . '/scripts/package-plugin.php';
require_once dirname( __DIR__, 3 ) . '/scripts/check-release-tag.php';

/**
 * The packager keeps development files out of the zip.
 */
final class ReleaseContractTest extends TestCase {
	public function testBetaVersionsFitTheScoreColumn(): void {
		$this->assertTrue( seostudio_version_valid( '0.6.0' ) );
		$this->assertTrue( seostudio_version_valid( '0.6.0-dev.1' ) );
		$this->assertTrue( seostudio_version_valid( '0.6.0-beta.1' ) );
		$this->assertFalse( seostudio_version_valid( '0.6.0-beta' ) );
		$this->assertFalse( seostudio_version_valid( '0.6.0-rc.1' ) );
		$this->assertFalse( seostudio_version_valid( '0.6.0-beta.123456789' ) );
	}

	public function testThePackageAllowlistRejectsBuildAndSecretPaths(): void {
		$this->assertFalse( seostudio_package_rejected( 'seo-studio.php' ) );
		$this->assertFalse( seostudio_package_rejected( 'src/Core/Plugin.php' ) );
		$this->assertFalse( seostudio_package_rejected( 'assets/build/dashboard.js' ) );
		$this->assertFalse( seostudio_package_rejected( 'vendor/autoload.php' ) );
		$this->assertTrue( seostudio_package_rejected( 'assets/src/dashboard/index.tsx' ) );
		$this->assertTrue( seostudio_package_rejected( 'dist/seo-studio/seo-studio.php' ) );
		$this->assertTrue( seostudio_package_rejected( 'tests/php/Unit/ReleaseContractTest.php' ) );
		$this->assertTrue( seostudio_package_rejected( '.github/workflows/release.yml' ) );
		$this->assertTrue( seostudio_package_rejected( '.env' ) );
		$this->assertTrue( seostudio_package_rejected( 'src/.env.local' ) );
		$this->assertTrue( seostudio_package_rejected( '.git/config' ) );
		$this->assertTrue( seostudio_package_rejected( '../seo-studio.php' ) );
	}

	public function testCheckoutVersionsMatch(): void {
		$this->assertSame( 0, seostudio_version_main() );
	}

	public function testAMatchingBetaTagAgreesAndAMismatchFails(): void {
		$this->assertSame( '', seostudio_release_identity_error( 'v0.6.0-beta.1', '0.6.0-beta.1', '0.6.0-beta.1', '0.6.0-beta.1' ) );
		$this->assertNotSame( '', seostudio_release_identity_error( 'v0.6.0-beta.2', '0.6.0-beta.1', '0.6.0-beta.1', '0.6.0-beta.1' ) );
		$this->assertNotSame( '', seostudio_release_identity_error( 'v0.6.0-beta.1', '0.6.0-dev.1', '0.6.0-beta.1', '0.6.0-beta.1' ) );
		$this->assertNotSame( '', seostudio_release_identity_error( 'v0.6.0-beta.1', '0.6.0-beta.1', '0.6.0-beta.1', '0.6.0' ) );
		$this->assertSame( 0, seostudio_release_tag_main( array( 'check-release-tag.php', 'v0.6.0-beta.1' ) ) );
		$this->assertSame( 1, seostudio_release_tag_main( array( 'check-release-tag.php', 'v0.6.0-beta.2' ) ) );
	}
}
