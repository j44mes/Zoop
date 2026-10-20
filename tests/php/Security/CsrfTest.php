<?php
/**
 * The meta box and sidebar save through REST, which needs a valid nonce under cookie auth.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Security;

use SEOStudio\Core\Plugin;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A forged cross-site save carries the victim's cookie but cannot carry a wp_rest nonce.
 */
final class CsrfTest extends WP_UnitTestCase {
	private int $admin = 0;

	private int $postId = 0;

	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		do_action( 'init' );
		do_action( 'rest_api_init' );
		$this->admin                    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->postId                   = self::factory()->post->create();
		$GLOBALS['wp_rest_auth_cookie'] = true;
	}

	public function tear_down(): void {
		unset( $GLOBALS['wp_rest_auth_cookie'], $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	public function test_a_cookie_save_without_a_nonce_runs_as_a_visitor(): void {
		wp_set_current_user( $this->admin );
		$this->assertTrue( rest_cookie_check_errors( null ) );

		$response = rest_do_request( $this->save() );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'seostudio_forbidden', $response->get_data()['code'] );
		$this->assertSame( '', get_post_meta( $this->postId, '_seostudio_title', true ) );
	}

	public function test_a_cookie_save_with_a_forged_nonce_is_rejected(): void {
		wp_set_current_user( $this->admin );
		$_SERVER['HTTP_X_WP_NONCE'] = 'forged';
		$result                     = rest_cookie_check_errors( null );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cookie_invalid_nonce', $result->get_error_code() );
	}

	public function test_a_cookie_save_with_the_editor_nonce_is_accepted(): void {
		wp_set_current_user( $this->admin );
		$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
		$this->assertTrue( rest_cookie_check_errors( null ) );

		$this->assertSame( 200, rest_do_request( $this->save() )->get_status() );
		$this->assertSame( 'Saved title', get_post_meta( $this->postId, '_seostudio_title_proposal', true ) );
		$this->assertSame( '', get_post_meta( $this->postId, '_seostudio_title', true ) );
	}

	private function save(): WP_REST_Request {
		$request = new WP_REST_Request( 'PUT', '/seostudio/v1/posts/' . $this->postId . '/seo' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( '{"title":"Saved title","proposal_revision":0}' );
		return $request;
	}
}
