<?php
/**
 * Script payloads in the keyphrase and title never reach the page unescaped.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Security;

use SEOStudio\Core\Plugin;
use SEOStudio\Core\Settings;
use SEOStudio\Output\HeadOutput;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The REST layer strips tags; Owner-mode output escapes values that bypassed it.
 */
final class XssTest extends WP_UnitTestCase {
	private const PAYLOADS = array(
		'"><script>alert(1)</script>',
		'</script><script>alert(2)</script>',
		'<img src=x onerror=alert(3)>',
	);

	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		do_action( 'init' );
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_the_rest_api_strips_markup_from_keyphrase_and_title(): void {
		$post_id = self::factory()->post->create();
		foreach ( self::PAYLOADS as $payload ) {
			$request = new WP_REST_Request( 'PUT', '/seostudio/v1/posts/' . $post_id . '/seo' );
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body(
				(string) wp_json_encode(
					array(
						'focus_keyphrase' => 'rank ' . $payload,
						'title'           => 'Title ' . $payload,
					)
				)
			);
			$this->assertSame( 200, rest_do_request( $request )->get_status() );
			foreach ( array( '_seostudio_focus_keyphrase', '_seostudio_title' ) as $key ) {
				$stored = (string) get_post_meta( $post_id, $key, true );
				$this->assertStringNotContainsString( '<script', $stored, $key );
				$this->assertStringNotContainsString( '<img', $stored, $key );
			}
		}
	}

	public function test_importers_strip_markup_from_vendor_values(): void {
		foreach ( self::PAYLOADS as $payload ) {
			$post_id = self::factory()->post->create();
			update_post_meta( $post_id, '_yoast_wpseo_title', 'Yoast ' . $payload );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', 'Yoast ' . $payload );
			( new \SEOStudio\Integrations\Importers\Yoast() )->import( $post_id - 1, 1 );
			foreach ( array( '_seostudio_title', '_seostudio_description' ) as $key ) {
				$this->assertStringNotContainsString( '<script', (string) get_post_meta( $post_id, $key, true ), $key );
				$this->assertStringNotContainsString( '<img', (string) get_post_meta( $post_id, $key, true ), $key );
			}
		}
	}

	public function test_owner_mode_escapes_values_that_bypassed_the_api(): void {
		$settings         = Settings::all();
		$settings['mode'] = 'owner';
		update_option( Settings::OPTION, $settings, true );

		foreach ( self::PAYLOADS as $payload ) {
			$post_id = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_title'  => 'Post ' . $payload,
				)
			);
			// Written straight to meta, as legacy data or another plugin could.
			update_post_meta( $post_id, '_seostudio_title', 'Title ' . $payload );
			update_post_meta( $post_id, '_seostudio_description', 'Description ' . $payload );
			update_post_meta( $post_id, '_seostudio_og_title', 'Social ' . $payload );
			$this->go_to( (string) get_permalink( $post_id ) );

			ob_start();
			( new HeadOutput() )->render();
			$head  = (string) ob_get_clean();
			$title = wp_get_document_title();

			foreach ( array( $head, $title ) as $html ) {
				$this->assertStringNotContainsString( '<script>alert', $html );
				$this->assertStringNotContainsString( '<img src=x', $html );
				$this->assertStringNotContainsString( '"><', $html );
			}
			// One JSON-LD block, which the payload cannot close early.
			$this->assertSame( 1, substr_count( $head, '</script>' ) );
		}
	}
}
