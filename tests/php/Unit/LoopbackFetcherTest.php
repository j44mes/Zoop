<?php
/**
 * Fetch size and redirect boundary tests.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Content\Extractor;
use SEOStudio\Content\FetchBlockedException;
use SEOStudio\Content\FetchPolicy;
use SEOStudio\Content\LoopbackFetcher;

/**
 * The fetcher follows two same-host redirects and rejects a truncated body.
 */
final class LoopbackFetcherTest extends TestCase {
	public function testFollowsOneRedirect(): void {
		$model = $this->fetch( array( $this->redirect( '/done' ), $this->ok( '<html><body><main><p>Done.</p></main></body></html>' ) ) );
		$this->assertStringContainsString( 'Done.', $model->bodyText );
	}

	public function testFollowsTwoRedirects(): void {
		$model = $this->fetch(
			array(
				$this->redirect( '/second' ),
				$this->redirect( '/done' ),
				$this->ok( '<html><body><main><p>Done.</p></main></body></html>' ),
			)
		);
		$this->assertStringContainsString( 'Done.', $model->bodyText );
	}

	public function testRejectsTheThirdRedirect(): void {
		$this->expectException( FetchBlockedException::class );
		$this->fetch(
			array(
				$this->redirect( '/two' ),
				$this->redirect( '/three' ),
				$this->redirect( '/four' ),
				$this->ok( '<p>Nope.</p>' ),
			)
		);
	}

	public function testRejectsABodyOneByteOverTheCap(): void {
		$calls = array();
		try {
			$this->fetch(
				array( $this->ok( str_repeat( 'a', LoopbackFetcher::MAX_BYTES + 1 ) ) ),
				$calls
			);
			$this->fail( 'An oversized body was accepted.' );
		} catch ( FetchBlockedException $exception ) {
			$this->assertSame( 'oversized', $exception->reason );
		}

		$this->assertSame( LoopbackFetcher::MAX_BYTES + 1, $calls[0]['limit_response_size'] );
	}

	public function testAcceptsABodyOfExactlyTwoMegabytes(): void {
		$model = $this->fetch( array( $this->ok( str_repeat( 'a', LoopbackFetcher::MAX_BYTES ) ) ) );
		$this->assertNotSame( '', $model->url );
	}

	public function testRejectsANon200Response(): void {
		$this->expectException( FetchBlockedException::class );
		$this->fetch( array( array( 'code' => 404, 'location' => '', 'body' => 'Missing' ) ) );
	}

	public function testRejectsATransportThatReturnsNoArray(): void {
		$this->expectException( FetchBlockedException::class );
		$policy    = new FetchPolicy( static fn (): array => array( '93.184.216.34' ) );
		$transport = static fn (): mixed => null;
		( new LoopbackFetcher( $policy, new Extractor(), $transport ) )->fetch(
			'https://example.test/start',
			array( 'home_url' => 'https://example.test/' )
		);
	}

	public function testUsesWpRemoteGetWhenNoTransportIsInjected(): void {
		$GLOBALS['seostudio_remote_response'] = new \WP_Error( 'http', 'failed' );
		$policy = new FetchPolicy( static fn (): array => array( '93.184.216.34' ) );
		try {
			( new LoopbackFetcher( $policy ) )->fetch( 'https://example.test/start', array( 'home_url' => 'https://example.test/' ) );
			$this->fail( 'A transport error was accepted.' );
		} catch ( FetchBlockedException $exception ) {
			$this->assertSame( 'blocked', $exception->reason );
		}

		$GLOBALS['seostudio_remote_response'] = array(
			'code'    => 200,
			'headers' => array( 'location' => '', 'x-robots-tag' => 'noindex' ),
			'body'    => '<html><body><main><p>Remote body.</p></main></body></html>',
		);
		$model = ( new LoopbackFetcher( $policy ) )->fetch( 'https://example.test/start', array( 'home_url' => 'https://example.test/' ) );
		$this->assertStringContainsString( 'Remote body.', $model->bodyText );
		$this->assertSame( 'noindex', $model->robots );
		$this->assertSame( 'observed_html', $model->metadataStatus );
	}

	public function testSendsBasicAuthWhenConfigured(): void {
		if ( ! defined( 'SEOSTUDIO_FETCH_USER' ) ) {
			define( 'SEOSTUDIO_FETCH_USER', 'audit' );
			define( 'SEOSTUDIO_FETCH_PASS', 'secret' );
		}

		$calls = array();
		$this->fetch( array( $this->ok( '<html><body><main><p>Authed.</p></main></body></html>' ) ), $calls );
		$this->assertSame( 'Basic ' . base64_encode( 'audit:secret' ), $calls[0]['headers']['Authorization'] );
	}

	/**
	 * @param array<int, array{code: int, location: string, body: string}> $script
	 * @param array<int, array<string, mixed>>                              $calls
	 */
	private function fetch( array $script, array &$calls = array() ): \SEOStudio\Content\PageModel {
		$policy = new FetchPolicy( static fn (): array => array( '93.184.216.34' ) );
		$transport = function ( string $url, array $args ) use ( &$script, &$calls ): array {
			unset( $url );
			$calls[] = $args;
			$next    = array_shift( $script );
			return is_array( $next ) ? $next : array( 'code' => 500, 'location' => '', 'body' => '' );
		};

		return ( new LoopbackFetcher( $policy, new Extractor(), $transport ) )->fetch(
			'https://example.test/start',
			array( 'home_url' => 'https://example.test/' )
		);
	}

	/**
	 * @return array{code: int, location: string, body: string}
	 */
	private function redirect( string $path ): array {
		return array(
			'code'     => 302,
			'location' => 'https://example.test' . $path,
			'body'     => '',
		);
	}

	/**
	 * @return array{code: int, location: string, body: string}
	 */
	private function ok( string $body ): array {
		return array(
			'code'     => 200,
			'location' => '',
			'body'     => $body,
		);
	}
}
