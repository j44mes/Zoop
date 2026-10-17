<?php
/**
 * Settings import treats uploaded files as untrusted.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Security;

use SEOStudio\Core\Plugin;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * No unserialize, the editor's field contract, and per-post permissions.
 */
final class ImportExportTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		do_action( 'init' );
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_export_round_trips_decoded_values(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_seostudio_title', 'Exported title' );
		update_post_meta(
			$post_id,
			'_seostudio_robots',
			array(
				'index'             => false,
				'follow'            => true,
				'max_snippet'       => null,
				'max_image_preview' => null,
			)
		);
		update_post_meta( $post_id, '_seostudio_is_news', '1' );

		$export = rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/export' ) )->get_data();
		$rows   = array();
		foreach ( $export['meta'] as $row ) {
			if ( $post_id === $row['post_id'] ) {
				$rows[ $row['meta_key'] ] = $row['meta_value'];
			}
		}
		$this->assertSame( 'Exported title', $rows['_seostudio_title'] );
		$this->assertIsArray( $rows['_seostudio_robots'], 'Arrays are exported decoded, not as serialized PHP.' );
		$this->assertTrue( $rows['_seostudio_is_news'] );

		delete_post_meta( $post_id, '_seostudio_title' );
		delete_post_meta( $post_id, '_seostudio_robots' );
		$result = $this->import( array( 'meta' => $export['meta'] ) );
		$this->assertSame( 0, $result['meta_skipped'] );
		$this->assertSame( 'Exported title', get_post_meta( $post_id, '_seostudio_title', true ) );
		$this->assertFalse( get_post_meta( $post_id, '_seostudio_robots', true )['index'] );
	}

	public function test_serialized_php_is_never_unserialized(): void {
		$post_id = self::factory()->post->create();
		$payload = serialize( new \ArrayObject( array( 'index' => false ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The attack payload under test.
		$result  = $this->import(
			array(
				'meta' => array(
					array(
						'post_id'    => $post_id,
						'meta_key'   => '_seostudio_robots', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Import row, not a query.
						'meta_value' => $payload, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Import row, not a query.
					),
				),
			)
		);

		$this->assertSame( 0, $result['meta_written'] );
		$this->assertSame( 'Serialized values are not accepted.', $result['skipped'][0]['reason'] );
		$this->assertSame( '', get_post_meta( $post_id, '_seostudio_robots', true ) );
	}

	public function test_imported_fields_follow_the_editor_contract(): void {
		$post_id = self::factory()->post->create();
		$result  = $this->import(
			array(
				'meta' => array(
					$this->row( $post_id, '_seostudio_canonical', 'https://elsewhere.test/page' ),
					$this->row( $post_id, '_seostudio_title', str_repeat( 'a', 301 ) ),
					$this->row( $post_id, '_seostudio_description', '<script>alert(1)</script>Kept text' ),
					$this->row( $post_id, 'wp_capabilities', 'administrator' ),
					$this->row( 999999, '_seostudio_title', 'Missing post' ),
				),
			)
		);

		$this->assertSame( 1, $result['meta_written'] );
		$this->assertSame( 4, $result['meta_skipped'] );
		$this->assertSame( '', get_post_meta( $post_id, '_seostudio_canonical', true ), 'An off-site canonical is rejected.' );
		$this->assertSame( '', get_post_meta( $post_id, '_seostudio_title', true ) );
		$this->assertStringNotContainsString( '<script>', get_post_meta( $post_id, '_seostudio_description', true ) );
		$this->assertSame( '', get_post_meta( 999999, '_seostudio_title', true ) );
	}

	/**
	 * One import row.
	 *
	 * @return array<string, mixed>
	 */
	private function row( int $postId, string $key, mixed $value ): array {
		return array(
			'post_id'    => $postId,
			'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Import row, not a query.
			'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Import row, not a query.
		);
	}

	/**
	 * POST an import body and return the response data.
	 *
	 * @param array<string, mixed> $body
	 * @return array<string, mixed>
	 */
	private function import( array $body ): array {
		$request = new WP_REST_Request( 'POST', '/seostudio/v1/import' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data();
	}
}
