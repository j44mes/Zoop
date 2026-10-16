<?php
/**
 * Page analysis routes.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Content\Renderer;
use SEOStudio\Core\Capabilities;
use SEOStudio\Scoring\OnPage\Scorer;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Storage\ScoresRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Lists analysed pages and runs the on-page scorer.
 */
final class PagesController {
	public function __construct(
		private readonly PagesRepository $pages = new PagesRepository(),
		private readonly ScoresRepository $scores = new ScoresRepository(),
		private readonly Renderer $renderer = new Renderer(),
		private readonly Scorer $scorer = new Scorer()
	) {}

	public function register(): void {
		register_rest_route(
			'seostudio/v1',
			'/pages/(?P<page_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'canReadPage' ),
				),
			)
		);

		register_rest_route(
			'seostudio/v1',
			'/pages/(?P<page_id>\d+)/analyse',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'analyse' ),
					'permission_callback' => array( $this, 'canReadPage' ),
					'args'                => array(
						'keyphrase' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	public function canReadPage( WP_REST_Request $request ): bool {
		$page = $this->pages->find( (int) $request['page_id'] );
		if ( null === $page ) {
			return Capabilities::canEditPosts();
		}
		return Capabilities::canEditPost( (int) $page['object_id'] );
	}

	public function show( WP_REST_Request $request ): WP_REST_Response {
		$page = $this->pages->find( (int) $request['page_id'] );
		if ( null === $page ) {
			return new WP_REST_Response( array( 'code' => 'seostudio_not_found' ), 404 );
		}
		return new WP_REST_Response( $page );
	}

	public function analyse( WP_REST_Request $request ): WP_REST_Response {
		$page = $this->pages->find( (int) $request['page_id'] );
		if ( null === $page ) {
			return new WP_REST_Response(
				array(
					'code'    => 'seostudio_not_found',
					'message' => __( 'That page has not been analysed yet.', 'seo-studio' ),
				),
				404
			);
		}

		$model = $this->renderer->renderPost( (int) $page['object_id'] );
		$score = $this->scorer->score( $model, (string) $request['keyphrase'] );
		$this->pages->storeModel( (int) $page['object_id'], $model, md5( $model->bodyText ) );
		$this->scores->insert( (int) $page['id'], (string) $request['keyphrase'], $score, SEOSTUDIO_VERSION );

		return new WP_REST_Response( $score->toArray() );
	}
}
