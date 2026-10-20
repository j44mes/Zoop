<?php
/**
 * Localhost observation stays off unless every gate matches.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Content\FetchBlockedException;
use SEOStudio\Content\FetchPolicy;
use SEOStudio\Content\LoopbackFetcher;
use SEOStudio\Content\LoopbackPin;
use SEOStudio\Content\LocalObservation;

/**
 * Override matrix, DNS rebinding, and loopback pinning.
 */
final class LocalObservationTest extends TestCase {
	protected function tearDown(): void {
		putenv( 'WP_ENVIRONMENT_TYPE' );
		unset(
			$GLOBALS['seostudio_home'],
			$GLOBALS['seostudio_run_http_hooks'],
			$GLOBALS['seostudio_curl_info'],
			$GLOBALS['seostudio_remote_response'],
			$GLOBALS['seostudio_remote_responses'],
			$GLOBALS['seostudio_remote_socket'],
			$GLOBALS['seostudio_socket_log'],
			$GLOBALS['seostudio_remote_calls'],
			$GLOBALS['seostudio_hooks'],
			$GLOBALS['seostudio_proxy_enabled'],
			$GLOBALS['seostudio_proxy_send'],
			$GLOBALS['wp_filter']
		);
		parent::tearDown();
	}

	public function testOverrideDisabledBlocksEveryListedHost(): void {
		$observation = new LocalObservation();
		$raw         = array(
			'scheme' => 'http',
			'host'   => 'localhost',
			'port'   => 80,
		);
		foreach ( $this->hosts() as $host ) {
			$this->assertFalse(
				$observation->decision( $host, 'http', 80, 'http', 'localhost', 80, $raw, false, 'local' ),
				$host
			);
		}

		$this->expectException( FetchBlockedException::class );
		( new FetchPolicy( static fn (): array => array( '127.0.0.1' ) ) )->assertAllowed( 'http://localhost/page', 'http://localhost/' );
	}

	public function testEnabledOverrideAllowsOnlyLiteralLocalhost(): void {
		$observation = new LocalObservation();
		$raw         = array(
			'scheme' => 'http',
			'host'   => 'localhost',
			'port'   => 80,
		);
		$this->assertTrue( $observation->decision( 'localhost', 'http', 80, 'http', 'localhost', 80, $raw, true, 'local' ) );
		$this->assertTrue( $observation->decision( 'localhost', 'http', 80, 'http', 'localhost', 80, $raw, true, 'development' ) );
		foreach ( array( 'production', 'staging', '' ) as $environment ) {
			$this->assertFalse( $observation->decision( 'localhost', 'http', 80, 'http', 'localhost', 80, $raw, true, $environment ) );
		}
		$this->assertFalse( $observation->decision( 'localhost', 'http', 80, 'http', 'localhost', 8889, $raw, true, 'local' ) );
		$this->assertFalse( $observation->decision( 'localhost', 'https', 443, 'http', 'localhost', 80, $raw, true, 'local' ) );
		$this->assertFalse(
			$observation->decision(
				'localhost',
				'http',
				80,
				'http',
				'localhost',
				80,
				array(
					'scheme' => 'https',
					'host'   => 'example.test',
					'port'   => 443,
				),
				true,
				'local'
			)
		);
		foreach ( array( 'localhost.localdomain', 'printer.local', 'db.internal', '127.0.0.1', '::1', 'rebind.test', 'example.test' ) as $host ) {
			$this->assertFalse( $observation->decision( $host, 'http', 80, 'http', $host, 80, $raw, true, 'local' ), $host );
		}
		$this->assertFalse( $observation->decision( '127.0.0.1', 'http', 80, 'http', 'localhost', 80, $raw, true, 'local' ) );
		$this->assertFalse(
			$observation->decision(
				'localhost',
				'http',
				80,
				'http',
				'localhost',
				80,
				array(
					'scheme' => 'http',
					'host'   => '127.0.0.1',
					'port'   => 80,
				),
				true,
				'local'
			)
		);

		$this->enable();
		foreach ( array( 'http://127.0.0.1/page', 'http://[::1]/page', 'http://printer.local/page', 'http://db.internal/page', 'http://localhost.localdomain/page', 'http://rebind.test/page' ) as $url ) {
			try {
				( new FetchPolicy( static fn (): array => array( '127.0.0.1' ) ) )->assertAllowed( $url, $url );
				$this->fail( 'Allowed ' . $url );
			} catch ( FetchBlockedException $exception ) {
				$this->assertNotSame( '', $exception->getMessage() );
			}
		}
	}

	public function testRebindingHostnameNeverConnects(): void {
		$calls    = 0;
		$resolver = static function () use ( &$calls ): array {
			++$calls;
			return 1 === $calls ? array( '127.0.0.1' ) : array( '8.8.8.8' );
		};
		$policy = new FetchPolicy( $resolver );
		try {
			$policy->assertAllowed( 'http://rebind.test/secret', 'http://rebind.test/' );
			$this->fail( 'A rebinding hostname was allowed.' );
		} catch ( FetchBlockedException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		}
		$this->assertSame( 1, $calls );
		$this->assertNotSame( '8.8.8.8', '127.0.0.1' );
	}

	public function testEnabledLocalhostIgnoresALaterPublicAddress(): void {
		$this->enable();
		$calls    = 0;
		$resolver = static function () use ( &$calls ): array {
			++$calls;
			return array( '8.8.8.8' );
		};
		$url = ( new FetchPolicy( $resolver ) )->assertAllowed( 'http://localhost/page', 'http://localhost/' );
		$this->assertSame( 'http://localhost/page', $url );
		$this->assertSame( 0, $calls );

		try {
			( new FetchPolicy( $resolver ) )->assertAllowed( 'http://localhost/page', 'http://localhost:8889/' );
			$this->fail( 'A different port was allowed.' );
		} catch ( FetchBlockedException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		}
		try {
			( new FetchPolicy( $resolver ) )->nextHop( 'http://localhost/start', 'https://evil.test/next', 'http://localhost/', 0 );
			$this->fail( 'A redirect left localhost.' );
		} catch ( FetchBlockedException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		}
		try {
			( new FetchPolicy( $resolver ) )->nextHop( 'http://localhost/start', 'http://127.0.0.1/next', 'http://localhost/', 0 );
			$this->fail( 'A redirect became an IP literal.' );
		} catch ( FetchBlockedException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		}
	}

	public function testPinForcesLoopbackAndDropsAReboundAddress(): void {
		$pin    = new LoopbackPin( 'http://localhost/page', '127.0.0.1' );
		$socket = 'tcp://8.8.8.8:80';
		$pin->pinSocket( $socket );
		$this->assertSame( 'tcp://127.0.0.1:80', $socket );
		$secure = 'ssl://localhost:443';
		$https  = new LoopbackPin( 'https://localhost/page', '127.0.0.1' );
		$https->pinSocket( $secure );
		$this->assertSame( 'ssl://127.0.0.1:443', $secure );

		$pin->verifyCurl( array(), array( 'primary_ip' => '8.8.8.8' ) );
		try {
			$pin->assertSafe();
			$this->fail( 'A rebound address was accepted.' );
		} catch ( FetchBlockedException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		}

		$clean = new LoopbackPin( 'http://localhost/page', '127.0.0.1' );
		$clean->verifyCurl( array(), array( 'primary_ip' => '127.0.0.1' ) );
		$loop = 'tcp://localhost:80';
		$clean->pinSocket( $loop );
		$clean->assertSafe();
		$this->assertSame( 'tcp://127.0.0.1:80', $loop );
	}

	public function testCurlPinRequiresSuccessfulOptions(): void {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'cURL is not installed.' );
		}

		$pin    = new LoopbackPin( 'http://localhost/page', '127.0.0.1' );
		$handle = curl_init();
		$pin->pinCurl( $handle, array(), 'http://localhost/page' );
		$pin->verifyCurl( array(), array( 'primary_ip' => '127.0.0.1' ) );
		$pin->assertSafe();
		unset( $handle );

		$broken = new LoopbackPin( 'http://localhost/page', '127.0.0.1' );
		$closed = new \stdClass();
		try {
			$broken->pinCurl( $closed, array(), 'http://localhost/page' );
			$this->fail( 'A failed cURL option was accepted.' );
		} catch ( FetchBlockedException $exception ) {
			$this->assertFalse( $broken->pinned() );
		}
	}

	public function testUnsupportedSocketAndMissingPinFailClosed(): void {
		$pin    = new LoopbackPin( 'http://localhost/page', '127.0.0.1' );
		$socket = 'udp://localhost:80';
		try {
			$pin->pinSocket( $socket );
			$this->fail( 'An unsupported socket was pinned.' );
		} catch ( FetchBlockedException $exception ) {
			$this->assertFalse( $pin->pinned() );
		}

		$this->enable();
		$GLOBALS['seostudio_run_http_hooks']  = false;
		$GLOBALS['seostudio_remote_response'] = array(
			'code'    => 200,
			'headers' => array(
				'location'     => '',
				'x-robots-tag' => '',
			),
			'body'    => '<html><head><title>Leaked</title></head><body><main><p>Leaked</p></main></body></html>',
		);
		try {
			( new LoopbackFetcher( new FetchPolicy( static fn (): array => array( '8.8.8.8' ) ) ) )->fetch(
				'http://localhost/page',
				array( 'home_url' => 'http://localhost/' )
			);
			$this->fail( 'A response without a pin was accepted.' );
		} catch ( FetchBlockedException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		}
	}

	public function testPinnedLocalhostFetchKeepsTheLoopbackAddress(): void {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'cURL is not installed.' );
		}

		$this->enable();
		$GLOBALS['seostudio_run_http_hooks']  = true;
		$GLOBALS['seostudio_remote_socket']   = 'tcp://8.8.8.8:80';
		$GLOBALS['seostudio_remote_response'] = array(
			'code'    => 200,
			'headers' => array(
				'location'     => '',
				'x-robots-tag' => '',
			),
			'body'    => '<html><head><title>how to price a cleaning contract</title></head><body><main><p>Body</p></main></body></html>',
		);
		$model = ( new LoopbackFetcher( new FetchPolicy( static fn (): array => array( '8.8.8.8' ) ) ) )->fetch(
			'http://localhost/page',
			array( 'home_url' => 'http://localhost/' )
		);
		$this->assertSame( 'how to price a cleaning contract', $model->titleTag );
		$this->assertSame( 'observed', $model->titleEvidence );
		$this->assertSame( 'tcp://127.0.0.1:80', $GLOBALS['seostudio_remote_socket'] );
		$this->assertSame( array(), $GLOBALS['seostudio_hooks']['requests-curl.before_send'][ PHP_INT_MAX ] ?? array() );
		$this->assertSame( array(), $GLOBALS['seostudio_hooks']['http_api_curl'][ PHP_INT_MAX ] ?? array() );
	}

	public function testALaterTransportCallbackFailsClosed(): void {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'cURL is not installed.' );
		}

		$this->enable();
		$GLOBALS['seostudio_run_http_hooks']  = true;
		$GLOBALS['seostudio_remote_calls']    = 0;
		$GLOBALS['seostudio_remote_response'] = array(
			'code'    => 200,
			'headers' => array(
				'location'     => '',
				'x-robots-tag' => '',
			),
			'body'    => '<html><head><title>Leaked</title></head><body><main><p>Leaked</p></main></body></html>',
		);
		add_action(
			'http_api_curl',
			static function (): void {
				add_action( 'requests-curl.before_send', static function (): void {}, PHP_INT_MAX );
			},
			0
		);

		try {
			( new LoopbackFetcher( new FetchPolicy( static fn (): array => array( '8.8.8.8' ) ) ) )->fetch(
				'http://localhost/page',
				array( 'home_url' => 'http://localhost/' )
			);
			$this->fail( 'A callback registered after the pin was allowed to run.' );
		} catch ( FetchBlockedException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		}
		$this->assertSame( 1, $GLOBALS['seostudio_remote_calls'] );
	}

	public function testCurlWithoutTheFinalPinFailsClosed(): void {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'cURL is not installed.' );
		}

		$pin    = new LoopbackPin( 'http://localhost/page', '127.0.0.1' );
		$pin->arm();
		$handle = curl_init();
		try {
			$pin->pinCurl( $handle, array(), 'http://localhost/page' );
			$pin->verifyCurl( array(), array( 'primary_ip' => '127.0.0.1' ) );
			$pin->assertSafe();
			$this->fail( 'cURL evidence was accepted before the final pin.' );
		} catch ( FetchBlockedException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		} finally {
			$pin->disarm();
			unset( $handle );
		}
	}

	public function testAnEarlierEvidenceCallbackCannotSupplyLoopback(): void {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'cURL is not installed.' );
		}

		$pin = new LoopbackPin( 'http://localhost/page', '127.0.0.1' );
		add_action( 'requests-curl.after_request', static function (): void {}, PHP_INT_MIN );
		$pin->arm();
		$handle = curl_init();
		try {
			$pin->sealCurl( $handle );
			$pin->verifyCurl( array(), array( 'primary_ip' => '127.0.0.1' ) );
			$pin->assertSafe();
			$this->fail( 'Evidence from a callback ahead of the pin was accepted.' );
		} catch ( FetchBlockedException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		} finally {
			$pin->disarm();
			unset( $handle );
		}
	}

	public function testProxyInterferenceFailsBeforeAConnection(): void {
		$this->enable();
		$GLOBALS['seostudio_proxy_enabled']   = true;
		$GLOBALS['seostudio_proxy_send']      = true;
		$GLOBALS['seostudio_run_http_hooks']  = true;
		$GLOBALS['seostudio_remote_calls']    = 0;
		$GLOBALS['seostudio_remote_response'] = array(
			'code'    => 200,
			'headers' => array(
				'location'     => '',
				'x-robots-tag' => '',
			),
			'body'    => '<html><head><title>Proxied</title></head><body><main><p>Proxied</p></main></body></html>',
		);

		try {
			( new LoopbackFetcher( new FetchPolicy( static fn (): array => array( '8.8.8.8' ) ) ) )->fetch(
				'http://localhost/page',
				array( 'home_url' => 'http://localhost/' )
			);
			$this->fail( 'A proxied localhost request was fetched.' );
		} catch ( FetchBlockedException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		}
		$this->assertSame( 0, $GLOBALS['seostudio_remote_calls'] );
	}

	public function testRefusedLoopbackRetriesTheOtherLiteralAddress(): void {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'cURL is not installed.' );
		}

		$this->enable();
		$GLOBALS['seostudio_run_http_hooks']    = true;
		$GLOBALS['seostudio_remote_socket']     = 'tcp://8.8.8.8:80';
		$GLOBALS['seostudio_remote_responses']  = array(
			new \WP_Error( 'http', 'connection refused' ),
			array(
				'code'    => 200,
				'headers' => array(
					'location'     => '',
					'x-robots-tag' => '',
				),
				'body'    => '<html><head><title>how to price a cleaning contract</title></head><body><main><p>Body</p></main></body></html>',
			),
		);
		$model = ( new LoopbackFetcher( new FetchPolicy( static fn (): array => array( '8.8.8.8' ) ) ) )->fetch(
			'http://localhost/page',
			array( 'home_url' => 'http://localhost/' )
		);
		$this->assertSame( 'observed', $model->titleEvidence );
		$this->assertSame( array( 'tcp://127.0.0.1:80', 'tcp://[::1]:80' ), $GLOBALS['seostudio_socket_log'] );
		$this->assertSame( 2, $GLOBALS['seostudio_remote_calls'] );
	}

	public function testAnUnpinnedFailureDoesNotOpenASecondDial(): void {
		$this->enable();
		$GLOBALS['seostudio_run_http_hooks']  = false;
		$GLOBALS['seostudio_remote_calls']    = 0;
		$GLOBALS['seostudio_remote_response'] = new \WP_Error( 'http', 'connection refused' );

		try {
			( new LoopbackFetcher( new FetchPolicy( static fn (): array => array( '8.8.8.8' ) ) ) )->fetch(
				'http://localhost/page',
				array( 'home_url' => 'http://localhost/' )
			);
			$this->fail( 'An unpinned failure continued to a second address.' );
		} catch ( FetchBlockedException $exception ) {
			$this->assertNotSame( '', $exception->getMessage() );
		}
		$this->assertSame( 1, $GLOBALS['seostudio_remote_calls'] );
	}

	public function testWordPressHookOrderAcceptsOnlyTheFinalPin(): void {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'cURL is not installed.' );
		}

		$pin    = new LoopbackPin( 'http://localhost/page', '127.0.0.1' );
		$pin->arm();
		$before = new \WP_Hook();
		$before->callbacks = array(
			PHP_INT_MAX => array(
				'seal' => array(
					'function'      => array( $pin, 'sealCurl' ),
					'accepted_args' => 1,
				),
			),
		);
		$after = new \WP_Hook();
		$after->callbacks = array(
			PHP_INT_MIN => array(
				'verify' => array(
					'function'      => array( $pin, 'verifyCurl' ),
					'accepted_args' => 2,
				),
			),
		);
		$GLOBALS['wp_filter']['requests-curl.before_send'] = $before;
		$GLOBALS['wp_filter']['requests-curl.after_request'] = $after;
		$handle = curl_init();
		try {
			$pin->sealCurl( $handle );
			$pin->verifyCurl( array(), array( 'primary_ip' => '127.0.0.1' ) );
			$pin->assertSafe();
			$this->assertTrue( $pin->pinned() );
		} finally {
			$pin->disarm();
			unset( $handle );
		}
	}

	public function testWordPressHookOrderRejectsACallbackAfterThePin(): void {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'cURL is not installed.' );
		}

		$pin    = new LoopbackPin( 'http://localhost/page', '127.0.0.1' );
		$pin->arm();
		$before = new \WP_Hook();
		$before->callbacks = array(
			PHP_INT_MAX => array(
				'ours'  => array(
					'function'      => array( $pin, 'sealCurl' ),
					'accepted_args' => 1,
				),
				'later' => array(
					'function'      => static function (): void {},
					'accepted_args' => 1,
				),
			),
		);
		$GLOBALS['wp_filter']['requests-curl.before_send'] = $before;
		$handle = curl_init();
		try {
			$pin->sealCurl( $handle );
			$this->fail( 'A later WordPress callback was allowed to follow the pin.' );
		} catch ( FetchBlockedException $exception ) {
			$this->assertFalse( $pin->pinned() );
		} finally {
			$pin->disarm();
			unset( $handle );
		}
	}

	public function testPublicHostFetchingDoesNotArmThePin(): void {
		putenv( 'WP_ENVIRONMENT_TYPE=production' );
		$GLOBALS['seostudio_home']            = 'http://example.test';
		$GLOBALS['seostudio_run_http_hooks']  = true;
		$GLOBALS['seostudio_remote_calls']    = 0;
		$GLOBALS['seostudio_remote_socket']   = 'tcp://93.184.216.34:80';
		$GLOBALS['seostudio_hooks']           = array();
		$GLOBALS['seostudio_remote_response'] = array(
			'code'    => 200,
			'headers' => array(
				'location'     => '',
				'x-robots-tag' => '',
			),
			'body'    => '<html><head><title>Public page</title></head><body><main><p>Body</p></main></body></html>',
		);

		$model = ( new LoopbackFetcher( new FetchPolicy( static fn (): array => array( '93.184.216.34' ) ) ) )->fetch(
			'http://example.test/page',
			array( 'home_url' => 'http://example.test/' )
		);
		$this->assertSame( 'Public page', $model->titleTag );
		$this->assertSame( 'tcp://93.184.216.34:80', $GLOBALS['seostudio_remote_socket'] );
		$this->assertSame( array(), $GLOBALS['seostudio_hooks'] );
		$this->assertSame( 1, $GLOBALS['seostudio_remote_calls'] );
	}

	/**
	 * @return string[]
	 */
	private function hosts(): array {
		return array( 'localhost', 'localhost.localdomain', 'printer.local', 'db.internal', '127.0.0.1', '::1', 'rebind.test' );
	}

	private function enable(): void {
		if ( ! defined( 'SEOSTUDIO_LOCAL_OBSERVE' ) ) {
			define( 'SEOSTUDIO_LOCAL_OBSERVE', true );
		}
		putenv( 'WP_ENVIRONMENT_TYPE=local' );
		$GLOBALS['seostudio_home'] = 'http://localhost';
	}
}
