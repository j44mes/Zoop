<?php
/**
 * Public site boundary.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Architecture\UrlKey;

/**
 * home_url path is the boundary. The installation directory is not.
 */
final class SiteBoundaryTest extends TestCase {
	public function testPublicRootIncludesPagesOutsideTheWordPressDirectory(): void {
		$home = 'https://example.test/';
		$this->assertTrue( UrlKey::sameSite( UrlKey::identity( 'https://example.test/about' ), $home ) );
		$this->assertTrue( UrlKey::sameSite( UrlKey::identity( 'https://example.test/wp/wp-admin/' ), $home ) );
	}

	public function testPublicSubdirectoryExcludesAnotherApplication(): void {
		$home = 'https://example.test/blog/';
		$this->assertTrue( UrlKey::sameSite( UrlKey::identity( 'https://example.test/blog/about' ), $home ) );
		$this->assertFalse( UrlKey::sameSite( UrlKey::identity( 'https://example.test/shop' ), $home ) );
	}

	public function testUnverifiedWwwAndOtherHostsStayOutside(): void {
		$home = 'https://example.test/';
		$this->assertFalse( UrlKey::sameSite( UrlKey::identity( 'https://www.example.test/about' ), $home ) );
		$this->assertFalse( UrlKey::sameSite( UrlKey::identity( 'https://blog.example.test/about' ), $home ) );
		$this->assertFalse( UrlKey::sameSite( UrlKey::identity( 'https://example.test:8443/about' ), $home ) );
	}

	public function testExplicitAliasAndPortAreOptIn(): void {
		$home = 'https://example.test:8080/';
		$this->assertTrue(
			UrlKey::sameSite(
				UrlKey::identity( 'https://www.example.test:8080/about' ),
				$home,
				array( 'www.example.test:8080' )
			)
		);
		$this->assertFalse(
			UrlKey::sameSite(
				UrlKey::identity( 'https://www.example.test/about' ),
				$home,
				array( 'www.example.test:8080' )
			)
		);
	}

	public function testTrackingParametersDoNotChangeIdentity(): void {
		$plain = UrlKey::identity( 'https://example.test/about?p=4' );
		$tracked = UrlKey::identity( 'https://example.test/about?p=4&utm_source=ad' );
		$this->assertSame( $plain, $tracked );
		$this->assertNotSame( $plain, UrlKey::identity( 'https://example.test/about?p=5' ) );
	}
}
