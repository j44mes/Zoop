<?php
/**
 * Page analysis routes.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Content\FetchBlockedException;
use SEOStudio\Content\LoopbackFetcher;
use SEOStudio\Content\PageModel;
use SEOStudio\Content\Renderer;
use SEOStudio\Core\Capabilities;
use SEOStudio\Scoring\OnPage\Scorer;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Storage\ScoresRepository;
use WP_Error;
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
		private readonly Scorer $scorer = new Scorer(),
		private readonly LoopbackFetcher $fetcher = new LoopbackFetcher(),
		private readonly PageRecord $records = new PageRecord()
	) {}

	public function register(): void {
		register_rest_route(
			'seostudio/v1',
			'/pages',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'index' ),
					'permission_callback' => static function (): bool {
						return Capabilities::canEditPosts();
					},
					'args'                => $this->listArgs(),
				),
			)
		);

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
					'callback'            => array( $this, 'analyseExisting' ),
					'permission_callback' => array( $this, 'canReadPage' ),
					'args'                => $this->analyseArgs(),
				),
			)
		);

		register_rest_route(
			'seostudio/v1',
			'/analyse/(?P<object_type>[a-z0-9_-]+)/(?P<object_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'analyseObject' ),
					'permission_callback' => array( $this, 'canEditObject' ),
					'args'                => $this->analyseArgs(),
				),
			)
		);
	}

	/**
	 * Query arguments for the page list.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function listArgs(): array {
		$string = static function (): array {
			return array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			);
		};

		return array(
			'page'          => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page'      => array(
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'search'        => $string(),
			'object_id'     => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'post_type'     => $string(),
			'rating'        => array(
				'type'              => 'string',
				'enum'              => array( 'red', 'orange', 'green', 'grey' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'score_min'     => array(
				'type' => 'integer',
			),
			'score_max'     => array(
				'type' => 'integer',
			),
			'orderby'       => array(
				'type'              => 'string',
				'enum'              => array( 'title', 'score', 'last_rendered_at' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'order'         => array(
				'type'              => 'string',
				'enum'              => array( 'asc', 'desc' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'has_keyphrase' => array(
				'type' => 'boolean',
			),
			'score_state'   => array(
				'type'              => 'string',
				'enum'              => array( 'needs_reanalysis' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'visibility'    => array(
				'type'              => 'string',
				'enum'              => array( 'noindex' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'suggestions'   => array(
				'type' => 'boolean',
			),
		);
	}

	/**
	 * Body arguments for an analysis request.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function analyseArgs(): array {
		return array(
			'mode'       => array(
				'type'              => 'string',
				'enum'              => array( 'content', 'live' ),
				'default'           => 'content',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'keyphrase'  => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( mixed $value ): bool {
					return ! is_string( $value ) || mb_strlen( $value ) <= 255;
				},
			),
			'keyphrases' => array(
				'type'  => 'array',
				'items' => array(
					'type' => 'string',
				),
			),
		);
	}

	public function canReadPage( WP_REST_Request $request ): bool {
		$page = $this->pages->find( (int) $request['page_id'] );
		if ( null === $page ) {
			return Capabilities::canEditPosts();
		}

		return Capabilities::canEditPost( (int) $page['object_id'] );
	}

	public function canEditObject( WP_REST_Request $request ): bool {
		if ( 'post' !== (string) $request['object_type'] ) {
			return Capabilities::canEditPosts();
		}

		return Capabilities::canEditPost( (int) $request['object_id'] );
	}

	public function index( WP_REST_Request $request ): WP_REST_Response {
		return Responses::json( $this->records->collection( $request ) );
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$record = $this->records->full( (int) $request['page_id'] );
		if ( null === $record ) {
			return Responses::error( 'seostudio_not_found', __( 'That page has not been analysed yet.', 'seo-studio' ), 404 );
		}

		return Responses::json( $record );
	}

	public function analyseExisting( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$page = $this->pages->find( (int) $request['page_id'] );
		if ( null === $page ) {
			return Responses::error( 'seostudio_not_found', __( 'That page has not been analysed yet.', 'seo-studio' ), 404 );
		}

		return $this->scorePost( (int) $page['object_id'], $request );
	}

	public function analyseObject( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( 'post' !== (string) $request['object_type'] ) {
			return Responses::error( 'seostudio_validation', __( 'Only posts can be analysed in this phase.', 'seo-studio' ), 400, 'object_type' );
		}

		$post = get_post( (int) $request['object_id'] );
		if ( ! $post instanceof \WP_Post ) {
			return Responses::error( 'seostudio_not_found', __( 'That post does not exist.', 'seo-studio' ), 404 );
		}

		return $this->scorePost( $post->ID, $request );
	}

	private function scorePost( int $postId, WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$keyphrases = $this->keyphrases( $postId, $request );
		if ( $keyphrases instanceof WP_Error ) {
			return $keyphrases;
		}

		$mode = (string) ( $request->get_param( 'mode' ) ?? 'content' );
		try {
			$model = 'live' === $mode ? $this->liveModel( $postId ) : $this->renderer->renderPost( $postId );
		} catch ( FetchBlockedException $exception ) {
			$code = 'oversized' === $exception->reason ? 'seostudio_fetch_failed' : 'seostudio_fetch_blocked';
			return Responses::error( $code, $exception->getMessage(), 400, '', $exception->reason );
		}

		$model   = $this->pages->mergeMeasurements( $postId, $model );
		$page_id = $this->pages->storeModel( $postId, $model, $model->inputHash() );
		$stored  = $this->pages->loadModel( $postId );
		if ( $stored instanceof PageModel ) {
			$model = $stored;
		}
		foreach ( $keyphrases as $keyphrase ) {
			$this->scores->insert( $page_id, $keyphrase, $this->scorer->score( $model, $keyphrase ), SEOSTUDIO_VERSION, $model->inputHash() );
		}

		$record = $this->records->full( $page_id );
		return Responses::json( is_array( $record ) ? $record : array() );
	}

	/**
	 * Keyphrases from the request, or the stored focus keyphrase.
	 *
	 * @return string[]|WP_Error
	 */
	private function keyphrases( int $postId, WP_REST_Request $request ): array|WP_Error {
		$many = $request->get_param( 'keyphrases' );
		if ( is_array( $many ) && array() !== $many ) {
			$list = array();
			foreach ( $many as $keyphrase ) {
				if ( ! is_string( $keyphrase ) || '' === trim( $keyphrase ) || mb_strlen( $keyphrase ) > 255 ) {
					return Responses::error( 'seostudio_validation', __( 'A keyphrase is not valid.', 'seo-studio' ), 400, 'keyphrases' );
				}
				$list[] = $keyphrase;
			}
			return $list;
		}

		$single = $request->get_param( 'keyphrase' );
		if ( is_string( $single ) && '' !== trim( $single ) ) {
			return array( $single );
		}

		$stored = get_post_meta( $postId, '_seostudio_focus_keyphrase', true );
		if ( is_string( $stored ) && '' !== trim( $stored ) ) {
			return array( $stored );
		}

		return Responses::error( 'seostudio_validation', __( 'A keyphrase is required.', 'seo-studio' ), 400, 'keyphrase' );
	}

	private function liveModel( int $postId ): PageModel {
		$url = get_permalink( $postId );
		$url = is_string( $url ) ? $url : '';
		return $this->fetcher->fetch(
			$url,
			array(
				'page_type'        => get_post_type( $postId ),
				'content_selector' => $this->renderer->selectorFor( get_post( $postId ) ),
			)
		);
	}
}
