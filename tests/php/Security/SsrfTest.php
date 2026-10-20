<?php
/**
 * Live analysis refuses every off-site or oversized fetch with the documented codes.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Security;

use SEOStudio\Content\FetchPolicy;
use SEOStudio\Content\LoopbackFetcher;
use SEOStudio\Core\Plugin;
use SEOStudio\Rest\PagesController;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * External host, IP literal, redirect off-host, and a body one byte over 2 MB.
 */
final class SsrfTest extends WP_UnitTestCase {
	private int $postId = 0;

	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// wp-env pins WP_HOME to localhost, which the policy blocks; pre_option wins over the constant.
		add_filter( 'pre_option_home', array( $this, 'home' ) );
		add_filter( 'pre_option_siteurl', array( $this, 'home' ) );
		$this->postId = self::factory()->post->create( array( 'post_status' => 'publish' ) );
	}

	public function home(): string {
		return 'https://example.test';
	}

	public function test_a_same_host_page_is_fetched(): void {
		$filter  = static fn (): string => 'https://example.test/page/';
		add_filter( 'post_link', $filter );
		$fetcher = new LoopbackFetcher(
			policy: new FetchPolicy( static fn (): array => array( '93.184.216.34' ) ),
			transport: $this->transport( 200, '', '<html><head><title>Fine</title></head><body><p>Body</p></body></html>' )
		);
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'object_type', 'post' );
		$request->set_param( 'object_id', $this->postId );
		$request->set_param( 'mode', 'live' );
		$request->set_param( 'keyphrase', 'probe' );
		$result = ( new PagesController( fetcher: $fetcher ) )->analyseObject( $request );
		remove_filter( 'post_link', $filter );

		$this->assertNotInstanceOf( WP_Error::class, $result, 'The control case must pass, or every refusal below proves nothing.' );
	}

	public function test_a_rest_url_parameter_cannot_choose_the_target(): void {
		$seen    = '';
		$filter  = static fn (): string => 'https://example.test/page/';
		add_filter( 'post_link', $filter );
		$fetcher = new LoopbackFetcher(
			policy: new FetchPolicy( static fn (): array => array( '93.184.216.34' ) ),
			transport: function ( string $url ) use ( &$seen ): array {
				$seen = $url;
				return array(
					'code'     => 200,
					'location' => '',
					'body'     => '<html><head><title>Fine</title></head><body><p>Body</p></body></html>',
				);
			}
		);
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'object_type', 'post' );
		$request->set_param( 'object_id', $this->postId );
		$request->set_param( 'mode', 'live' );
		$request->set_param( 'keyphrase', 'probe' );
		$request->set_param( 'url', 'https://evil.test/steal' );
		$result = ( new PagesController( fetcher: $fetcher ) )->analyseObject( $request );
		remove_filter( 'post_link', $filter );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'https://example.test/page/', $seen );
	}

	public function test_a_permalink_on_another_host_is_blocked(): void {
		$this->assertRefused( 'https://elsewhere.test/page/', $this->transport( 200, '', '<html></html>' ), 'seostudio_fetch_blocked' );
	}

	public function test_an_ip_literal_is_blocked(): void {
		foreach ( array( 'https://127.0.0.1/', 'https://[::1]/', 'https://169.254.169.254/latest/meta-data/' ) as $url ) {
			$this->assertRefused( $url, $this->transport( 200, '', '<html></html>' ), 'seostudio_fetch_blocked' );
		}
	}

	public function test_a_redirect_off_host_is_blocked(): void {
		$this->assertRefused( 'https://example.test/page/', $this->transport( 301, 'https://evil.test/steal', '' ), 'seostudio_fetch_blocked' );
	}

	public function test_a_body_over_two_megabytes_is_rejected_as_oversized(): void {
		$error = $this->assertRefused(
			'https://example.test/page/',
			$this->transport( 200, '', str_repeat( 'a', LoopbackFetcher::MAX_BYTES + 1 ) ),
			'seostudio_fetch_failed'
		);
		$this->assertSame( 'oversized', $error->get_error_data()['reason'] ?? '' );
	}

	/**
	 * Analyse the fixture post live with the permalink and transport given.
	 */
	private function assertRefused( string $permalink, callable $transport, string $code ): WP_Error {
		$filter = static fn (): string => $permalink;
		add_filter( 'post_link', $filter );
		try {
			$fetcher    = new LoopbackFetcher(
				policy: new FetchPolicy( static fn (): array => array( '93.184.216.34' ) ),
				transport: $transport
			);
			$controller = new PagesController( fetcher: $fetcher );
			$request    = new WP_REST_Request( 'POST' );
			$request->set_param( 'object_type', 'post' );
			$request->set_param( 'object_id', $this->postId );
			$request->set_param( 'mode', 'live' );
			$request->set_param( 'keyphrase', 'probe' );
			$result = $controller->analyseObject( $request );
		} finally {
			remove_filter( 'post_link', $filter );
		}

		$this->assertInstanceOf( WP_Error::class, $result, $permalink . ' was fetched.' );
		$this->assertSame( $code, $result->get_error_code(), $permalink );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? 0 );
		return $result;
	}

	/**
	 * A fake HTTP transport returning one fixed response.
	 */
	private function transport( int $code, string $location, string $body ): callable {
		return static fn (): array => array(
			'code'     => $code,
			'location' => $location,
			'body'     => $body,
		);
	}
}
