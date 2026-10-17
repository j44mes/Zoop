<?php
/**
 * Fetch allow-list tests.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Content\FetchBlockedException;
use SEOStudio\Content\FetchPolicy;

/**
 * SSRF guard. The resolver is injected so tests do not touch DNS.
 */
final class FetchPolicyTest extends TestCase {
	public function testAllowsTheSiteHost(): void {
		$policy = new FetchPolicy( static fn (): array => array( '93.184.216.34' ) );
		$url    = $policy->assertAllowed( 'https://example.test/rank-tracker', 'https://example.test/' );
		$this->assertSame( 'https://example.test/rank-tracker', $url );
	}

	public function testRejectsAnotherHost(): void {
		$this->expectException( FetchBlockedException::class );
		$policy = new FetchPolicy( static fn (): array => array( '93.184.216.34' ) );
		$policy->assertAllowed( 'https://evil.test/rank-tracker', 'https://example.test/' );
	}

	public function testRejectsIpLiterals(): void {
		$this->expectException( FetchBlockedException::class );
		$policy = new FetchPolicy( static fn (): array => array( '93.184.216.34' ) );
		$policy->assertAllowed( 'https://93.184.216.34/', 'https://93.184.216.34/' );
	}

	public function testRejectsPrivateResolution(): void {
		$this->expectException( FetchBlockedException::class );
		$policy = new FetchPolicy( static fn (): array => array( '10.0.0.8' ) );
		$policy->assertAllowed( 'https://example.test/secret', 'https://example.test/' );
	}

	public function testRejectsUserinfo(): void {
		$this->expectException( FetchBlockedException::class );
		$policy = new FetchPolicy( static fn (): array => array( '93.184.216.34' ) );
		$policy->assertAllowed( 'https://user:secret@example.test/', 'https://example.test/' );
	}

	public function testRejectsOffHostRedirect(): void {
		$this->expectException( FetchBlockedException::class );
		$policy = new FetchPolicy( static fn (): array => array( '93.184.216.34' ) );
		$policy->nextHop( 'https://example.test/start', 'https://evil.test/next', 'https://example.test/', 0 );
	}

	public function testFollowsSameHostRedirect(): void {
		$policy = new FetchPolicy( static fn (): array => array( '93.184.216.34' ) );
		$next   = $policy->nextHop( 'https://example.test/start', '/next', 'https://example.test/', 0 );
		$this->assertSame( 'https://example.test/next', $next );
	}

	public function testAllowsTheSecondRedirect(): void {
		$policy = new FetchPolicy( static fn (): array => array( '93.184.216.34' ) );
		$next   = $policy->nextHop( 'https://example.test/start', '/next', 'https://example.test/', 2 );
		$this->assertSame( 'https://example.test/next', $next );
	}

	public function testRejectsTheThirdRedirect(): void {
		$this->expectException( FetchBlockedException::class );
		$policy = new FetchPolicy( static fn (): array => array( '93.184.216.34' ) );
		$policy->nextHop( 'https://example.test/start', '/next', 'https://example.test/', 3 );
	}

	public function testResolvesARelativeRedirect(): void {
		$policy = new FetchPolicy( static fn (): array => array( '93.184.216.34' ) );
		$next   = $policy->nextHop( 'https://example.test:8443/dir/page', 'next', 'https://example.test:8443/', 1 );
		$this->assertSame( 'https://example.test:8443/dir/next', $next );
	}

	public function testRejectsARedirectWithoutAHost(): void {
		$this->expectException( FetchBlockedException::class );
		$policy = new FetchPolicy( static fn (): array => array( '93.184.216.34' ) );
		$policy->nextHop( 'not-a-url', 'next', 'https://example.test/', 0 );
	}

	public function testRejectsUrlsThatAreNotAbsoluteHttp(): void {
		$policy = new FetchPolicy( static fn (): array => array( '93.184.216.34' ) );
		foreach ( array( 'example.test/path', 'ftp://example.test/', 'https://ex%61mple.test/', 'https://user:secret@example.test/path' ) as $url ) {
			try {
				$policy->assertAllowed( $url, $url );
				$this->fail( 'Allowed ' . $url );
			} catch ( FetchBlockedException $exception ) {
				$this->assertNotSame( '', $exception->getMessage() );
			}
		}
	}

	public function testRejectsLocalAndUnresolvableHosts(): void {
		$policy = new FetchPolicy( static fn (): array => array( '93.184.216.34' ) );
		foreach ( array( 'https://localhost/page', 'https://printer.local/page', 'https://db.internal/page' ) as $url ) {
			try {
				$policy->assertAllowed( $url, $url );
				$this->fail( 'Allowed ' . $url );
			} catch ( FetchBlockedException $exception ) {
				$this->assertNotSame( '', $exception->getMessage() );
			}
		}

		$empty = new FetchPolicy( static fn (): array => array() );
		$bad   = new FetchPolicy( static fn () => 'nope' );
		$mixed = new FetchPolicy( static fn (): array => array( 10 ) );
		foreach ( array( $empty, $bad, $mixed ) as $blocked ) {
			try {
				$blocked->assertAllowed( 'https://example.test/page', 'https://example.test/' );
				$this->fail( 'A blocked resolver was allowed.' );
			} catch ( FetchBlockedException $exception ) {
				$this->assertNotSame( '', $exception->getMessage() );
			}
		}
	}

	public function testResolvesPublicDnsWhenNoResolverIsInjected(): void {
		$policy = new FetchPolicy();
		$url    = $policy->assertAllowed( 'https://example.com/', 'https://example.com/' );
		$this->assertSame( 'https://example.com/', $url );
	}
}
