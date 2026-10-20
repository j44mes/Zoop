<?php
/**
 * The localhost exception stays closed unless the server constant allows it.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Security;

use SEOStudio\Content\FetchBlockedException;
use SEOStudio\Content\FetchPolicy;
use SEOStudio\Content\LocalObservation;
use SEOStudio\Content\LoopbackPin;
use SEOStudio\Core\Plugin;
use WP_UnitTestCase;

/**
 * Environment and hostname gates with the constant absent.
 */
final class LocalObserveTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
	}

	public function tear_down(): void {
		putenv( 'WP_ENVIRONMENT_TYPE' );
		parent::tear_down();
	}

	public function test_localhost_stays_blocked_without_the_server_constant(): void {
		$this->assertFalse( ( new LocalObservation() )->configured() );
		update_option( 'home', 'http://localhost' );
		update_option( 'siteurl', 'http://localhost' );
		putenv( 'WP_ENVIRONMENT_TYPE=local' );

		foreach ( array( 'http://localhost/page', 'http://localhost:8889/page', 'https://localhost/page', 'http://127.0.0.1/', 'http://printer.local/', 'http://db.internal/', 'http://rebind.test/' ) as $url ) {
			try {
				( new FetchPolicy( static fn (): array => array( '127.0.0.1' ) ) )->assertAllowed( $url, $url );
				$this->fail( 'Allowed ' . $url );
			} catch ( FetchBlockedException $exception ) {
				$this->assertNotSame( '', $exception->getMessage() );
			}
		}
	}

	public function test_production_and_staging_stay_blocked_when_the_constant_is_on(): void {
		$observation = new LocalObservation();
		$raw         = array(
			'scheme' => 'http',
			'host'   => 'localhost',
			'port'   => 80,
		);
		$this->assertFalse( $observation->decision( 'localhost', 'http', 80, 'http', 'localhost', 80, $raw, true, 'production' ) );
		$this->assertFalse( $observation->decision( 'localhost', 'http', 80, 'http', 'localhost', 80, $raw, true, 'staging' ) );
	}

	public function test_a_callback_registered_after_the_pin_never_replaces_the_socket(): void {
		$pin = new LoopbackPin( 'http://localhost/page', '127.0.0.1' );
		$pin->arm();
		$later = static function ( string &$socket ): void {
			$socket = 'tcp://8.8.8.8:80';
		};
		add_action( 'requests-fsockopen.remote_socket', $later, PHP_INT_MAX, 1 );
		$socket = 'tcp://localhost:80';
		try {
			$pin->pinSocket( $socket );
			$this->fail( 'A later socket callback was allowed to choose the destination.' );
		} catch ( FetchBlockedException $exception ) {
			$this->assertSame( 'tcp://localhost:80', $socket );
			$this->assertFalse( $pin->pinned() );
			$this->assertNotSame( '', $exception->getMessage() );
		} finally {
			$pin->disarm();
			remove_action( 'requests-fsockopen.remote_socket', $later, PHP_INT_MAX );
		}
	}

	public function test_a_callback_registered_after_the_curl_pin_is_rejected(): void {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'cURL is not installed.' );
		}

		$pin = new LoopbackPin( 'http://localhost/page', '127.0.0.1' );
		$pin->arm();
		$later  = static function (): void {};
		$handle = curl_init();
		add_action( 'requests-curl.before_send', $later, PHP_INT_MAX, 1 );
		try {
			$pin->sealCurl( $handle );
			$this->fail( 'A later cURL callback was allowed to follow the pin.' );
		} catch ( FetchBlockedException $exception ) {
			$this->assertFalse( $pin->pinned() );
			$this->assertNotSame( '', $exception->getMessage() );
		} finally {
			$pin->disarm();
			remove_action( 'requests-curl.before_send', $later, PHP_INT_MAX );
			unset( $handle );
		}
	}
}
