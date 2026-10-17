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
		$rows  = $this->index->rowsFor( (string) $request['keyphrase'] );
		$posts = array();
		foreach ( $rows as $row ) {
			if ( ! Capabilities::canEditPost( $row['post_id'] ) ) {
				continue;
			}
			$posts[] = array(
				'post_id'    => $row['post_id'],
				'title'      => get_the_title( $row['post_id'] ),
				'is_primary' => $row['is_primary'],
			);
		}

		return new WP_REST_Response( array( 'posts' => $posts ) );
	}
}
