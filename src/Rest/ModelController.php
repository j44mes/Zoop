<?php
/**
 * Unsaved content analysis for the editor.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Content\Renderer;
use SEOStudio\Core\Capabilities;
use SEOStudio\Scoring\OnPage\Scorer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Builds a PageModel from editor content without writing it.
 */
final class ModelController {
	public function __construct(
		private readonly Renderer $renderer = new Renderer(),
		private readonly Scorer $scorer = new Scorer()
	) {}

	public function register(): void {
		register_rest_route(
			'seostudio/v1',
			'/posts/(?P<post_id>\d+)/model',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'canEdit' ),
					'args'                => array(
						'content_html'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'wp_kses_post',
						),
						'title'           => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'excerpt'         => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'seo_title'       => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'seo_description' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'keyphrase'       => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	public function canEdit( WP_REST_Request $request ): bool {
		return Capabilities::canEditPost( (int) $request['post_id'] );
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = get_post( (int) $request['post_id'] );
		if ( ! $post instanceof \WP_Post ) {
			return Responses::error( 'seostudio_not_found', __( 'That post does not exist.', 'seo-studio' ), 404 );
		}

		$html = (string) $request->get_param( 'content_html' );
		if ( strlen( $html ) > 2097152 ) {
			return Responses::error( 'seostudio_validation', __( 'The editor content is too large to analyse.', 'seo-studio' ), 400, 'content_html' );
		}

		// The request carries unsaved editor values; the resolver decides which of them a crawler would see.
		$draft = array();
		foreach ( array(
			'title'           => 'post_title',
			'excerpt'         => 'excerpt',
			'seo_title'       => 'seo_title',
			'seo_description' => 'seo_description',
		) as $param => $key ) {
			$value = $request->get_param( $param );
			if ( is_string( $value ) ) {
				$draft[ $key ] = $value;
			}
		}
		$model = $this->renderer->renderDraft( $post, $html, $draft );

		$data           = $model->toArray();
		$keyphrase      = trim( (string) $request->get_param( 'keyphrase' ) );
		$data['onpage'] = '' === $keyphrase ? null : $this->scorer->score( $model, $keyphrase )->toArray();
		return Responses::json( $data );
	}
}
