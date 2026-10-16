<?php
/**
 * Per-post SEO meta routes.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Core\Capabilities;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Reads and writes the SEO fields stored on a post.
 */
final class PostSeoController {
	private const FIELDS = array(
		'focus_keyphrase'  => '_seostudio_focus_keyphrase',
		'title'            => '_seostudio_title',
		'description'      => '_seostudio_description',
		'canonical'        => '_seostudio_canonical',
		'cornerstone'      => '_seostudio_cornerstone',
		'content_selector' => '_seostudio_content_selector',
	);

	public function register(): void {
		register_rest_route(
			'seostudio/v1',
			'/posts/(?P<post_id>\d+)/seo',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'canEdit' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'canEdit' ),
				),
			)
		);
	}

	public function canEdit( WP_REST_Request $request ): bool {
		return Capabilities::canEditPost( (int) $request['post_id'] );
	}

	public function show( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request['post_id'];
		$data    = array();
		foreach ( self::FIELDS as $name => $meta_key ) {
			$data[ $name ] = get_post_meta( $post_id, $meta_key, true );
		}
		return new WP_REST_Response( $data );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request['post_id'];
		$params  = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		foreach ( self::FIELDS as $name => $meta_key ) {
			if ( ! array_key_exists( $name, $params ) ) {
				continue;
			}
			$value = is_string( $params[ $name ] ) ? sanitize_text_field( $params[ $name ] ) : $params[ $name ];
			if ( 'description' === $name ) {
				$value = sanitize_textarea_field( (string) $params[ $name ] );
			}
			if ( 'canonical' === $name ) {
				$value = esc_url_raw( (string) $params[ $name ] );
			}
			if ( '' === $value || null === $value || false === $value ) {
				delete_post_meta( $post_id, $meta_key );
			} else {
				update_post_meta( $post_id, $meta_key, $value );
			}
		}

		return $this->show( $request );
	}
}
