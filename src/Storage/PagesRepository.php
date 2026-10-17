<?php
/**
 * Page row storage.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Storage;

use SEOStudio\Content\PageModel;

/**
 * Reads and writes seostudio_pages.
 */
final class PagesRepository {
	/**
	 * Insert or update the page row for an object.
	 *
	 * @param array<string, mixed> $row
	 */
	public function save( array $row ): int {
		global $wpdb;

		$table = Tables::pages();
		$data  = array(
			'object_type'      => (string) ( $row['object_type'] ?? 'post' ),
			'object_id'        => (int) ( $row['object_id'] ?? 0 ),
			'url'              => (string) ( $row['url'] ?? '' ),
			'url_hash'         => md5( (string) ( $row['url'] ?? '' ) ),
			'input_hash'       => (string) ( $row['input_hash'] ?? '' ),
			'page_model'       => wp_json_encode( $row['page_model'] ?? array() ),
			'render_mode'      => (string) ( $row['render_mode'] ?? 'content' ),
			'content_selector' => (string) ( $row['content_selector'] ?? '' ),
			'last_rendered_at' => current_time( 'mysql', true ),
			'last_error'       => isset( $row['last_error'] ) ? (string) $row['last_error'] : null,
			'is_indexable'     => empty( $row['is_indexable'] ) ? 0 : 1,
		);

		$existing = $this->findByObject( $data['object_type'], (int) $data['object_id'] );
		if ( null === $existing ) {
			$wpdb->insert( $table, $data );
			return (int) $wpdb->insert_id;
		}

		$wpdb->update( $table, $data, array( 'id' => (int) $existing['id'] ) );
		return (int) $existing['id'];
	}

	/**
	 * Load a page row by its id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $pageId ): ?array {
		global $wpdb;
		$table = Tables::pages();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $pageId ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Find by object.
	 *
	 * @return array<string, mixed>|null
	 */
	public function findByObject( string $type, int $objectId ): ?array {
		global $wpdb;
		$table = Tables::pages();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE object_type = %s AND object_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is internal.
				$type,
				$objectId
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Store a freshly scored model for a post.
	 */
	public function storeModel( int $postId, PageModel $model, string $inputHash ): int {
		return $this->save(
			array(
				'object_type'      => 'post',
				'object_id'        => $postId,
				'url'              => $model->url,
				'input_hash'       => $inputHash,
				'page_model'       => $model->toArray(),
				'render_mode'      => $model->renderMode,
				'content_selector' => $model->contentSelectorUsed,
				'is_indexable'     => true,
			)
		);
	}

	/**
	 * Restore the model saved for a post.
	 */
	public function loadModel( int $postId, string $type = 'post' ): ?PageModel {
		$row = $this->findByObject( $type, $postId );
		if ( null === $row ) {
			return null;
		}

		$decoded = json_decode( (string) ( $row['page_model'] ?? '' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Reading stored JSON. There is no wp_json_decode.
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		return PageModel::fromArray( $decoded );
	}

	/**
	 * Page rows joined to posts and the latest on-page score.
	 *
	 * @param array<string, mixed> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function search( array $filters ): array {
		global $wpdb;

		$pages  = Tables::pages();
		$scores = Tables::scores();
		$posts  = $wpdb->posts;
		$where  = array( "pages.object_type = 'post'" );
		$values = array();

		if ( isset( $filters['post_type'] ) && is_string( $filters['post_type'] ) && '' !== $filters['post_type'] ) {
			$where[]  = 'posts.post_type = %s';
			$values[] = $filters['post_type'];
		}

		if ( isset( $filters['search'] ) && is_string( $filters['search'] ) && '' !== $filters['search'] ) {
			$where[]  = 'posts.post_title LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
		}

		if ( isset( $filters['rating'] ) && is_string( $filters['rating'] ) && '' !== $filters['rating'] ) {
			$where[]  = 'scores.rating = %s';
			$values[] = $filters['rating'];
		}

		if ( isset( $filters['score_min'] ) && is_numeric( $filters['score_min'] ) ) {
			$where[]  = 'scores.score >= %d';
			$values[] = (int) $filters['score_min'];
		}

		if ( isset( $filters['score_max'] ) && is_numeric( $filters['score_max'] ) ) {
			$where[]  = 'scores.score <= %d';
			$values[] = (int) $filters['score_max'];
		}

		if ( array_key_exists( 'has_keyphrase', $filters ) && null !== $filters['has_keyphrase'] ) {
			$where[] = ! empty( $filters['has_keyphrase'] )
				? "COALESCE(scores.keyphrase, '') <> ''"
				: "COALESCE(scores.keyphrase, '') = ''";
		}

		$orderby = 'pages.last_rendered_at';
		$allowed = array(
			'title'            => 'posts.post_title',
			'score'            => 'scores.score',
			'last_rendered_at' => 'pages.last_rendered_at',
		);
		if ( isset( $filters['orderby'] ) && is_string( $filters['orderby'] ) && isset( $allowed[ $filters['orderby'] ] ) ) {
			$orderby = $allowed[ $filters['orderby'] ];
		}

		$order = ( isset( $filters['order'] ) && 'asc' === strtolower( (string) $filters['order'] ) ) ? 'ASC' : 'DESC';
		$sql   = "SELECT pages.*, posts.post_title, posts.post_type, posts.post_status,
			scores.score AS onpage_score, scores.denominator AS onpage_denominator,
			scores.rating AS onpage_rating, scores.details AS onpage_details, scores.keyphrase AS score_keyphrase,
			(SELECT rating FROM {$scores} seo_scores WHERE seo_scores.page_id = pages.id AND seo_scores.engine = 'seo' ORDER BY seo_scores.id DESC LIMIT 1) AS seo_rating,
			(SELECT rating FROM {$scores} read_scores WHERE read_scores.page_id = pages.id AND read_scores.engine = 'readability' ORDER BY read_scores.id DESC LIMIT 1) AS readability_rating
			FROM {$pages} pages
			INNER JOIN {$posts} posts ON pages.object_id = posts.ID
			LEFT JOIN {$scores} scores ON scores.id = (
				SELECT MAX(latest.id) FROM {$scores} latest WHERE latest.page_id = pages.id AND latest.engine = 'onpage'
			)
			WHERE " . implode( ' AND ', $where ) . " ORDER BY {$orderby} {$order}";

		if ( array() !== $values ) {
			$prepared = $wpdb->prepare( $sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders are added with the values.
			$sql      = is_string( $prepared ) ? $prepared : $sql;
		}

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above when the query has values. The no-value query interpolates only internal table names.
		return is_array( $rows ) ? $rows : array();
	}
}
