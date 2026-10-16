<?php
/**
 * Keyphrase usage route.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Core\Capabilities;
use SEOStudio\Storage\KeyphrasesIndex;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Reports other posts that already use a keyphrase.
 */
final class KeyphrasesController {
	public function __construct( private readonly KeyphrasesIndex $index = new KeyphrasesIndex() ) {}

	public function register(): void {
		register_rest_route(
			'seostudio/v1',
			'/keyphrases/usage',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'usage' ),
				'permission_callback' => static function (): bool {
					return Capabilities::canEditPosts();
				},
				'args'                => array(
					'keyphrase' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	public function usage( WP_REST_Request $request ): WP_REST_Response {
		$ids   = $this->index->postIdsFor( (string) $request['keyphrase'] );
		$posts = array();
		foreach ( $ids as $post_id ) {
			if ( ! Capabilities::canEditPost( $post_id ) ) {
				continue;
			}
			$posts[] = array(
				'post_id' => $post_id,
				'title'   => get_the_title( $post_id ),
			);
		}

		return new WP_REST_Response( array( 'posts' => $posts ) );
	}
}
