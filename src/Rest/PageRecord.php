<?php
/**
 * Page records returned by the REST API.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Core\Capabilities;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Storage\ScoresRepository;
use WP_REST_Request;

/**
 * Builds the list item and the full page record from storage.
 */
final class PageRecord {
	public function __construct(
		private readonly PagesRepository $pages = new PagesRepository(),
		private readonly ScoresRepository $scores = new ScoresRepository()
	) {}

	/**
	 * Full record for one page row, or null when the caller cannot edit it.
	 *
	 * @return array<string, mixed>|null
	 */
	public function full( int $pageId ): ?array {
		$row = $this->pages->find( $pageId );
		if ( null === $row || ! Capabilities::canEditPost( (int) $row['object_id'] ) ) {
			return null;
		}

		$decoded = json_decode( (string) ( $row['page_model'] ?? '' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Reading stored JSON. There is no wp_json_decode.
		$details = array();
		foreach ( $this->scores->latestForPage( $pageId ) as $score ) {
			$payload   = json_decode( (string) ( $score['details'] ?? '' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Reading stored JSON. There is no wp_json_decode.
			$details[] = array(
				'keyphrase'   => (string) ( $score['keyphrase'] ?? '' ),
				'engine'      => (string) ( $score['engine'] ?? '' ),
				'score'       => (int) ( $score['score'] ?? 0 ),
				'denominator' => (int) ( $score['denominator'] ?? 0 ),
				'rating'      => (string) ( $score['rating'] ?? '' ),
				'details'     => is_array( $payload ) ? $payload : array(),
			);
		}

		return array(
			'page_id'          => (int) $row['id'],
			'object_type'      => (string) $row['object_type'],
			'object_id'        => (int) $row['object_id'],
			'url'              => (string) $row['url'],
			'render_mode'      => (string) $row['render_mode'],
			'content_selector' => (string) $row['content_selector'],
			'last_rendered_at' => $row['last_rendered_at'],
			'last_error'       => $row['last_error'],
			'is_indexable'     => isset( $row['is_indexable'] ) ? (bool) $row['is_indexable'] : null,
			'page_model'       => is_array( $decoded ) ? $decoded : array(),
			'scores'           => $details,
		);
	}

	/**
	 * Filtered, capability-checked page list.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int, pages: int}
	 */
	public function collection( WP_REST_Request $request ): array {
		$filters  = array(
			'search'        => $request->get_param( 'search' ),
			'post_type'     => $request->get_param( 'post_type' ),
			'rating'        => $request->get_param( 'rating' ),
			'score_min'     => $request->get_param( 'score_min' ),
			'score_max'     => $request->get_param( 'score_max' ),
			'orderby'       => $request->get_param( 'orderby' ),
			'order'         => $request->get_param( 'order' ),
			'has_keyphrase' => $this->flag( $request->get_param( 'has_keyphrase' ) ),
		);
		$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?? 20 ) ) );
		$page     = max( 1, (int) ( $request->get_param( 'page' ) ?? 1 ) );
		$start    = ( $page - 1 ) * $per_page;
		$total    = 0;
		$ids      = array();
		$offset   = 0;
		// Exact totals must honour edit_post filters, including custom capabilities.
		// Scan only IDs in bounded batches; hydrate details for the requested page.
		do {
			$rows = $this->pages->search( $filters, 200, $offset );
			_prime_post_caches( array_map( 'intval', array_column( $rows, 'object_id' ) ), false, false );
			foreach ( $rows as $row ) {
				if ( ! Capabilities::canEditPost( (int) $row['object_id'] ) ) {
					continue;
				}
				if ( $total >= $start && count( $ids ) < $per_page ) {
					$ids[] = (int) $row['id'];
				}
				++$total;
			}
			$offset    += 200;
			$batch_size = count( $rows );
		} while ( 200 === $batch_size );
		$visible = array();
		if ( array() !== $ids ) {
			$filters['ids'] = $ids;
			foreach ( $this->pages->search( $filters, $per_page, 0, true ) as $row ) {
				$visible[] = $this->item( $row );
			}
		}

		return array(
			'items' => $visible,
			'total' => $total,
			'pages' => (int) max( 1, (int) ceil( $total / $per_page ) ),
		);
	}

	private function flag( mixed $value ): ?bool {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes' ), true );
	}

	/**
	 * One list row in the documented shape.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function item( array $row ): array {
		$object_id = (int) $row['object_id'];
		$keyphrase = (string) ( $row['focus_keyphrase'] ?? '' );

		$details = json_decode( (string) ( $row['onpage_details'] ?? '' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Reading stored JSON. There is no wp_json_decode.
		$issue   = '';
		if ( is_array( $details ) && isset( $details['assessments'] ) && is_array( $details['assessments'] ) ) {
			foreach ( $details['assessments'] as $assessment ) {
				if ( is_array( $assessment ) && isset( $assessment['status'] ) && 'pass' !== $assessment['status'] && 'not_checked' !== $assessment['status'] ) {
					$issue = (string) ( $assessment['id'] ?? '' );
					break;
				}
			}
		}

		$edit = get_edit_post_link( $object_id, 'raw' );

		return array(
			'page_id'            => (int) $row['id'],
			'object_type'        => (string) $row['object_type'],
			'object_id'          => $object_id,
			'title'              => (string) ( $row['post_title'] ?? '' ),
			'url'                => (string) $row['url'],
			'edit_url'           => is_string( $edit ) ? $edit : '',
			'keyphrase'          => $keyphrase,
			'onpage'             => array(
				'score'       => isset( $row['onpage_score'] ) ? (int) $row['onpage_score'] : null,
				'denominator' => isset( $row['onpage_denominator'] ) ? (int) $row['onpage_denominator'] : null,
			),
			'seo_rating'         => null,
			'readability_rating' => null,
			'top_issue'          => $issue,
			'last_rendered_at'   => $row['last_rendered_at'] ?? null,
			'last_error'         => $row['last_error'] ?? null,
		);
	}
}
