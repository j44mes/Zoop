<?php
/**
 * Stores the content-analysis ratings produced in the browser.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Core\Capabilities;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Storage\ScoresRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Persists SEO and readability results for a page that has already been analysed.
 */
final class ContentScoresController {
	public function __construct(
		private readonly PagesRepository $pages = new PagesRepository(),
		private readonly ScoresRepository $scores = new ScoresRepository()
	) {}

	public function register(): void {
		register_rest_route(
			'seostudio/v1',
			'/pages/(?P<page_id>\d+)/content-scores',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'store' ),
					'permission_callback' => array( $this, 'canEdit' ),
				),
			)
		);
	}

	public function canEdit( WP_REST_Request $request ): bool {
		$page = $this->pages->find( (int) $request['page_id'] );
		if ( null === $page ) {
			return Capabilities::canEditPosts();
		}

		return Capabilities::canEditPost( (int) $page['object_id'] );
	}

	public function store( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$page = $this->pages->find( (int) $request['page_id'] );
		if ( null === $page ) {
			return Responses::error( 'seostudio_not_found', __( 'That page has not been analysed yet.', 'seo-studio' ), 404 );
		}

		$params    = $request->get_json_params();
		$keyphrase = is_array( $params ) ? trim( (string) ( $params['keyphrase'] ?? '' ) ) : '';
		if ( '' === $keyphrase || mb_strlen( $keyphrase ) > 255 ) {
			return Responses::error( 'seostudio_validation', __( 'A keyphrase is required.', 'seo-studio' ), 400, 'keyphrase' );
		}

		foreach ( array( 'seo', 'readability' ) as $engine ) {
			$payload = is_array( $params ) ? ( $params[ $engine ] ?? null ) : null;
			if ( ! is_array( $payload ) ) {
				return Responses::error( 'seostudio_validation', __( 'Each engine needs a score.', 'seo-studio' ), 400, $engine );
			}
			$rating = (string) ( $payload['rating'] ?? '' );
			if ( ! in_array( $rating, array( 'red', 'orange', 'green', 'grey' ), true ) ) {
				return Responses::error( 'seostudio_validation', __( 'The rating is not valid.', 'seo-studio' ), 400, $engine );
			}
			$score = (int) ( $payload['score'] ?? -1 );
			if ( $score < 0 || $score > 100 ) {
				return Responses::error( 'seostudio_validation', __( 'The score must be between 0 and 100.', 'seo-studio' ), 400, $engine );
			}
			$this->scores->insertEngine(
				(int) $page['id'],
				$keyphrase,
				$engine,
				$score,
				100,
				$rating,
				array( 'assessments' => $this->assessments( $payload['assessments'] ?? array() ) )
			);
		}

		return Responses::json( array( 'stored' => true ) );
	}

	/**
	 * Keep a bounded list of assessment rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function assessments( mixed $rows ): array {
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$clean = array();
		foreach ( array_slice( $rows, 0, 40 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean[] = array(
				'id'     => sanitize_key( (string) ( $row['id'] ?? '' ) ),
				'score'  => (int) ( $row['score'] ?? 0 ),
				'rating' => sanitize_key( (string) ( $row['rating'] ?? '' ) ),
				'text'   => mb_substr( sanitize_text_field( (string) ( $row['text'] ?? '' ) ), 0, 500 ),
			);
		}

		return $clean;
	}
}
