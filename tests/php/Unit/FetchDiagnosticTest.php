<?php
/**
 * Published-page fetch refusals keep a status and a stable reason.
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
use SEOStudio\Content\ProtectionPage;

/**
 * Challenge pages are refused before extraction. Ordinary pages are not.
 */
final class FetchDiagnosticTest extends TestCase {
	public function testHttp202WithAProtectionMarkerIsNamed(): void {
		$exception = $this->refused( 202, (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/siteground-protection.html' ) );

		$this->assertSame( 'protection_page', $exception->reason );
		$this->assertSame( 202, $exception->status );
		$this->assertSame(
			"Couldn't check the published page. Your host returned a protection page (HTTP 202). Password protection or a bot check may be blocking Zoop.",
			$exception->getMessage()
		);
	}

	public function testHttp202WithoutAMarkerKeepsTheStatus(): void {
		$exception = $this->refused( 202, '<html><body><p>Accepted for processing.</p></body></html>' );

		$this->assertSame( 'http_status', $exception->reason );
		$this->assertSame( 202, $exception->status );
		$this->assertStringContainsString( 'HTTP 202', $exception->getMessage() );
		$this->assertStringNotContainsString( 'protection page', $exception->getMessage() );
	}

	public function testHttp403IsNotCalledACaptcha(): void {
		$exception = $this->refused( 403, '<html><body><p>Forbidden</p></body></html>' );

		$this->assertSame( 'http_status', $exception->reason );
		$this->assertSame( 403, $exception->status );
		$this->assertSame(
			"Couldn't check the published page. The host refused the request (HTTP 403).",
			$exception->getMessage()
		);
	}

	public function testHttp200ChallengeIsBlockedBeforeExtraction(): void {
		$flag      = new \stdClass();
		$flag->extracted = false;
		$extractor = new class( $flag ) extends Extractor {
			public function __construct( private \stdClass $flag ) {}

			public function extract( string $html, array $context = array() ): \SEOStudio\Content\PageModel {
				$this->flag->extracted = true;
				return parent::extract( $html, $context );
			}
		};
		$exception = $this->refused( 200, (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/siteground-protection.html' ), $extractor );

		$this->assertFalse( $flag->extracted );
		$this->assertSame( 'protection_page', $exception->reason );
		$this->assertSame( 200, $exception->status );
		$this->assertStringContainsString( 'HTTP 200', $exception->getMessage() );
	}

	public function testOrdinaryHtmlIsRead(): void {
		$model = $this->fetched( 200, '<html><head><title>About</title></head><body><main><p>Visible page.</p></main></body></html>' );

		$this->assertStringContainsString( 'Visible page.', $model->bodyText );
	}

	public function testMissingMainDoesNotBlockThePage(): void {
		$model = $this->fetched( 200, '<html><head><title>About</title></head><body><p>Visible page.</p></body></html>' );

		$this->assertStringContainsString( 'Visible page.', $model->bodyText );
	}

	public function testAPageThatMentionsCaptchaIsNotAChallenge(): void {
		$html = '<html><head><title>Contact</title></head><body><main><p>This form uses a CAPTCHA so spam cannot be submitted.</p></main></body></html>';

		$this->assertFalse( ProtectionPage::isChallenge( $html ) );
		$model = $this->fetched( 200, $html );
		$this->assertStringContainsString( 'CAPTCHA', $model->bodyText );
	}

	public function testAnEmpty200IsNotVerified(): void {
		$exception = $this->refused( 200, '   ' );

		$this->assertSame( 'invalid_response', $exception->reason );
		$this->assertSame( 200, $exception->status );
	}

	public function testANonHtmlContentTypeIsNotVerified(): void {
		$exception = $this->refused( 200, 'not a page', null, 'image/png' );

		$this->assertSame( 'invalid_response', $exception->reason );
	}

	public function testAMissingContentTypeStillReadsHtml(): void {
		$model = $this->fetched( 200, '<html><head><title>About</title></head><body><main><p>Visible page.</p></main></body></html>', '' );

		$this->assertStringContainsString( 'Visible page.', $model->bodyText );
	}

	public function testTransportFailureHasNoStatus(): void {
		$policy    = new FetchPolicy( static fn (): array => array( '93.184.216.34' ) );
		$transport = static fn (): mixed => null;
		try {
			( new LoopbackFetcher( $policy, new Extractor(), $transport ) )->fetch(
				'https://example.test/start',
				array( 'home_url' => 'https://example.test/' )
			);
			$this->fail( 'A transport failure was accepted.' );
		} catch ( FetchBlockedException $exception ) {
			$this->assertSame( 'transport_error', $exception->reason );
			$this->assertNull( $exception->status );
			$this->assertStringContainsString( 'could not reach the page', $exception->getMessage() );
			$this->assertStringNotContainsString( 'HTTP', $exception->getMessage() );
		}
	}

	private function refused( int $code, string $body, ?Extractor $extractor = null, string $type = 'text/html' ): FetchBlockedException {
		try {
			$this->fetched( $code, $body, $type, $extractor );
		} catch ( FetchBlockedException $exception ) {
			return $exception;
		}
		$this->fail( 'The response was accepted.' );
	}

	private function fetched( int $code, string $body, string $type = 'text/html', ?Extractor $extractor = null ): \SEOStudio\Content\PageModel {
		$policy    = new FetchPolicy( static fn (): array => array( '93.184.216.34' ) );
		$transport = static fn (): array => array(
			'code'         => $code,
			'location'     => '',
			'body'         => $body,
			'content_type' => $type,
		);

		return ( new LoopbackFetcher( $policy, $extractor ?? new Extractor(), $transport ) )->fetch(
			'https://example.test/start',
			array( 'home_url' => 'https://example.test/' )
		);
	}
}
