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

		$table     = Tables::pages();
		$url       = (string) ( $row['url'] ?? '' );
		$object_id = (int) ( $row['object_id'] ?? 0 );
		$type      = (string) ( $row['object_type'] ?? 'post' );
		$data      = array(
			'object_type'      => $type,
			'object_id'        => $object_id,
			'url'              => $url,
			'url_hash'         => '' === $url ? md5( $type . ':' . $object_id ) : md5( $url ),
			'input_hash'       => (string) ( $row['input_hash'] ?? '' ),
			'page_model'       => wp_json_encode( $row['page_model'] ?? array() ),
			'render_mode'      => (string) ( $row['render_mode'] ?? 'content' ),
			'content_selector' => (string) ( $row['content_selector'] ?? '' ),
			'last_rendered_at' => current_time( 'mysql', true ),
			'last_error'       => isset( $row['last_error'] ) ? (string) $row['last_error'] : null,
			'is_indexable'     => isset( $row['is_indexable'] ) ? (int) (bool) $row['is_indexable'] : null,
		);

		$existing = $this->findByObject( $data['object_type'], (int) $data['object_id'] );
		if ( null === $existing ) {
			$wpdb->insert( $table, $data );
			$page_id = (int) $wpdb->insert_id;
			if ( 'post' === $type ) {
				$this->syncFocus( $object_id );
			}
			return $page_id;
		}

		$wpdb->update( $table, $data, array( 'id' => (int) $existing['id'] ) );
		return (int) $existing['id'];
	}

	/**
	 * Copy the post's focus keyphrase and its latest on-page score onto the page row.
	 *
	 * The dashboard reads these columns, so it stays one indexed query at 10,000 rows. Called
	 * when a page row is created and whenever the focus keyphrase meta changes.
	 */
	public function syncFocus( int $postId ): void {
		global $wpdb;

		$page = $this->findByObject( 'post', $postId );
		if ( null === $page ) {
			return;
		}

		$phrase = get_post_meta( $postId, '_seostudio_focus_keyphrase', true );
		$phrase = is_string( $phrase ) ? trim( $phrase ) : '';
		$hash   = '' === $phrase ? '' : md5( mb_strtolower( $phrase, 'UTF-8' ) );
		$scores = Tables::scores();
		$score  = '' === $hash ? null : $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, score, denominator, rating FROM {$scores} WHERE page_id = %d AND keyphrase_hash = %s AND engine = 'onpage' ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
				(int) $page['id'],
				$hash
			),
			ARRAY_A
		);
		$score  = is_array( $score ) ? $score : null;

		$wpdb->update(
			Tables::pages(),
			array(
				'focus_keyphrase'    => mb_substr( $phrase, 0, 255 ),
				'focus_hash'         => $hash,
				'onpage_score_id'    => null === $score ? null : (int) $score['id'],
				'onpage_score'       => null === $score ? null : (int) $score['score'],
				'onpage_denominator' => null === $score ? null : (int) $score['denominator'],
				'onpage_rating'      => null === $score ? null : (string) $score['rating'],
			),
			array( 'id' => (int) $page['id'] )
		);
	}

	/**
	 * Point the page row at a new on-page score when it is for the current focus keyphrase.
	 */
	public function recordScore( int $pageId, string $keyphraseHash, int $scoreId, int $score, int $denominator, string $rating ): void {
		global $wpdb;

		$pages = Tables::pages();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$pages} SET onpage_score_id = %d, onpage_score = %d, onpage_denominator = %d, onpage_rating = %s WHERE id = %d AND focus_hash = %s AND focus_hash <> ''", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
				$scoreId,
				$score,
				$denominator,
				$rating,
				$pageId,
				$keyphraseHash
			)
		);
	}

	/**
	 * Fill the focus and score columns for every page row. Used by the schema 3 upgrade.
	 */
	public function backfillFocus(): void {
		global $wpdb;

		$pages  = Tables::pages();
		$scores = Tables::scores();
		$meta   = $wpdb->postmeta;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table names; no input.
		$wpdb->query(
			"UPDATE {$pages} pages
			LEFT JOIN (
				SELECT post_id, MAX(meta_id) AS meta_id FROM {$meta} WHERE meta_key = '_seostudio_focus_keyphrase' GROUP BY post_id
			) latest ON latest.post_id = pages.object_id
			LEFT JOIN {$meta} focus ON focus.meta_id = latest.meta_id
			SET pages.focus_keyphrase = LEFT(TRIM(COALESCE(focus.meta_value, '')), 255),
				pages.focus_hash = IF(TRIM(COALESCE(focus.meta_value, '')) = '', '', MD5(LOWER(TRIM(focus.meta_value)))),
				pages.onpage_score_id = NULL, pages.onpage_score = NULL, pages.onpage_denominator = NULL, pages.onpage_rating = NULL
			WHERE pages.object_type = 'post'"
		);
		$wpdb->query(
			"UPDATE {$pages} pages
			INNER JOIN (
				SELECT page_id, keyphrase_hash, MAX(id) AS id FROM {$scores} WHERE engine = 'onpage' GROUP BY page_id, keyphrase_hash
			) latest ON latest.page_id = pages.id AND latest.keyphrase_hash = pages.focus_hash
			INNER JOIN {$scores} scores ON scores.id = latest.id
			SET pages.onpage_score_id = scores.id, pages.onpage_score = scores.score,
				pages.onpage_denominator = scores.denominator, pages.onpage_rating = scores.rating
			WHERE pages.focus_hash <> ''"
		);
		// phpcs:enable
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
		$merged = $this->mergeMeasurements( $postId, $model );
		if ( $merged !== $model ) {
			$inputHash = $merged->inputHash();
		}

		return $this->save(
			array(
				'object_type'      => 'post',
				'object_id'        => $postId,
				'url'              => $merged->url,
				'input_hash'       => $inputHash,
				'page_model'       => $merged->toArray(),
				'render_mode'      => $merged->renderMode,
				'content_selector' => $merged->contentSelectorUsed,
				'is_indexable'     => Indexability::forPost( $postId, $merged ),
			)
		);
	}

	/**
	 * Copy live code measurements onto a content render that does not have them.
	 */
	public function mergeMeasurements( int $postId, PageModel $model ): PageModel {
		return $model->retainCodeMeasurements( $this->loadModel( $postId ) );
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
	 * Page rows joined to posts and the latest on-page score for the saved focus keyphrase.
	 *
	 * @param array<string, mixed> $filters
	 * @return array<int, array<string, mixed>>
	 */
	public function search( array $filters, int $limit = 200, int $offset = 0, bool $details = false ): array {
		global $wpdb;

		$scores  = Tables::scores();
		$query   = $this->listQuery( $filters );
		$orderby = 'pages.last_rendered_at';
		$allowed = array(
			'title'            => 'posts.post_title',
			'score'            => 'pages.onpage_score',
			'last_rendered_at' => 'pages.last_rendered_at',
		);
		if ( isset( $filters['orderby'] ) && is_string( $filters['orderby'] ) && isset( $allowed[ $filters['orderby'] ] ) ) {
			$orderby = $allowed[ $filters['orderby'] ];
		}

		$order   = ( isset( $filters['order'] ) && 'asc' === strtolower( (string) $filters['order'] ) ) ? 'ASC' : 'DESC';
		$columns = $details
			? "pages.id, pages.object_id, pages.object_type, pages.url, pages.last_rendered_at, pages.last_error,
				posts.post_title, pages.focus_keyphrase AS focus_keyphrase,
				pages.onpage_score AS onpage_score, pages.onpage_denominator AS onpage_denominator,
				(SELECT details FROM {$scores} detail WHERE detail.id = pages.onpage_score_id) AS onpage_details,
				(SELECT rating FROM {$scores} seo_scores WHERE seo_scores.page_id = pages.id AND seo_scores.engine = 'seo'
					AND pages.focus_hash <> '' AND seo_scores.keyphrase_hash = pages.focus_hash
					ORDER BY seo_scores.id DESC LIMIT 1) AS seo_rating,
				(SELECT rating FROM {$scores} read_scores WHERE read_scores.page_id = pages.id AND read_scores.engine = 'readability'
					AND pages.focus_hash <> '' AND read_scores.keyphrase_hash = pages.focus_hash
					ORDER BY read_scores.id DESC LIMIT 1) AS readability_rating"
			: 'pages.id, pages.object_id, pages.onpage_score AS onpage_score, pages.onpage_denominator AS onpage_denominator, pages.onpage_rating AS onpage_rating';
		$sql     = "SELECT {$columns} {$query['sql']} ORDER BY {$orderby} {$order}, pages.id {$order}";
		$sql    .= ' LIMIT ' . max( 1, min( 200, $limit ) ) . ' OFFSET ' . max( 0, $offset );

		$rows = $wpdb->get_results( $this->prepare( $sql, $query['values'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared by prepare().
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Total and dashboard summary for the same filters, in one query.
	 *
	 * @param array<string, mixed> $filters
	 * @return array{total: int, scored: int, unscored: int, failed: int, red: int, orange: int, green: int, average: ?int}
	 */
	public function aggregate( array $filters ): array {
		global $wpdb;

		$query = $this->listQuery( $filters );
		$sql   = "SELECT COUNT(*) AS total,
				SUM(pages.onpage_denominator > 0) AS scored,
				SUM(pages.onpage_denominator IS NULL OR pages.onpage_denominator = 0) AS unscored,
				SUM(pages.last_error IS NOT NULL AND pages.last_error <> '') AS failed,
				SUM(pages.onpage_rating = 'red') AS red, SUM(pages.onpage_rating = 'orange') AS orange, SUM(pages.onpage_rating = 'green') AS green,
				AVG(CASE WHEN pages.onpage_denominator > 0 THEN pages.onpage_score * 100 / pages.onpage_denominator END) AS average
			{$query['sql']}";
		$row   = $wpdb->get_row( $this->prepare( $sql, $query['values'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared by prepare().
		$row   = is_array( $row ) ? $row : array();

		return array(
			'total'    => (int) ( $row['total'] ?? 0 ),
			'scored'   => (int) ( $row['scored'] ?? 0 ),
			'unscored' => (int) ( $row['unscored'] ?? 0 ),
			'failed'   => (int) ( $row['failed'] ?? 0 ),
			'red'      => (int) ( $row['red'] ?? 0 ),
			'orange'   => (int) ( $row['orange'] ?? 0 ),
			'green'    => (int) ( $row['green'] ?? 0 ),
			'average'  => isset( $row['average'] ) ? (int) round( (float) $row['average'] ) : null,
		);
	}

	/**
	 * Matching IDs only; never load models or score details for permission checks.
	 *
	 * @param array<string, mixed> $filters
	 * @return array<int, array{id: int|string, object_id: int|string}>
	 */
	public function permissionCandidates( array $filters ): array {
		global $wpdb;

		$query = $this->listQuery( $filters );
		$sql   = 'SELECT pages.id, pages.object_id ' . $query['sql'];
		$rows  = $wpdb->get_results( $this->prepare( $sql, $query['values'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared by prepare().
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * FROM and WHERE shared by the list and its aggregate.
	 *
	 * Filters and sorts read the focus and score columns kept on the page row by syncFocus()
	 * and recordScore(). Joining scores and post meta per row took seconds at 10,000 rows.
	 *
	 * @param array<string, mixed> $filters
	 * @return array{sql: string, values: array<int, mixed>}
	 */
	private function listQuery( array $filters ): array {
		global $wpdb;

		$pages  = Tables::pages();
		$posts  = $wpdb->posts;
		$where  = array( "pages.object_type = 'post'", "posts.post_status NOT IN ('trash', 'auto-draft', 'inherit')" );
		$values = array();

		if ( isset( $filters['editable_sql'] ) && is_string( $filters['editable_sql'] ) ) {
			$where[] = $filters['editable_sql'];
		}

		if ( isset( $filters['object_id'] ) && is_numeric( $filters['object_id'] ) && (int) $filters['object_id'] > 0 ) {
			$where[]  = 'pages.object_id = %d';
			$values[] = (int) $filters['object_id'];
		}

		if ( isset( $filters['post_type'] ) && is_string( $filters['post_type'] ) && '' !== $filters['post_type'] ) {
			$where[]  = 'posts.post_type = %s';
			$values[] = $filters['post_type'];
		}

		if ( isset( $filters['search'] ) && is_string( $filters['search'] ) && '' !== $filters['search'] ) {
			$where[]  = 'posts.post_title LIKE %s';
			$values[] = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
		}

		if ( isset( $filters['rating'] ) && is_string( $filters['rating'] ) && '' !== $filters['rating'] ) {
			$where[]  = 'pages.onpage_rating = %s';
			$values[] = $filters['rating'];
		}

		if ( isset( $filters['score_min'] ) && is_numeric( $filters['score_min'] ) ) {
			$where[]  = 'pages.onpage_score >= %d';
			$values[] = (int) $filters['score_min'];
		}

		if ( isset( $filters['score_max'] ) && is_numeric( $filters['score_max'] ) ) {
			$where[]  = 'pages.onpage_score <= %d';
			$values[] = (int) $filters['score_max'];
		}

		if ( array_key_exists( 'has_keyphrase', $filters ) && null !== $filters['has_keyphrase'] ) {
			$where[] = ! empty( $filters['has_keyphrase'] )
				? "pages.focus_keyphrase <> ''"
				: "pages.focus_keyphrase = ''";
		}

		if ( isset( $filters['ids'] ) && is_array( $filters['ids'] ) ) {
			$ids     = array_map( 'intval', $filters['ids'] );
			$where[] = 'pages.id IN (' . implode( ',', array( 0, ...$ids ) ) . ')';
		}

		$sql = "FROM {$pages} pages INNER JOIN {$posts} posts ON pages.object_id = posts.ID WHERE " . implode( ' AND ', $where );

		return array(
			'sql'    => $sql,
			'values' => $values,
		);
	}

	/**
	 * Prepare a list query when it has placeholders.
	 *
	 * @param array<int, mixed> $values
	 */
	private function prepare( string $sql, array $values ): string {
		global $wpdb;

		if ( array() === $values ) {
			return $sql;
		}
		$prepared = $wpdb->prepare( $sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders are added with the values.
		return is_string( $prepared ) ? $prepared : $sql;
	}

	/**
	 * Remember a per-page failure without stopping the batch.
	 */
	public function recordError( int $postId, string $message ): void {
		global $wpdb;

		$existing = $this->findByObject( 'post', $postId );
		if ( null === $existing ) {
			$url = get_permalink( $postId );
			$this->save(
				array(
					'object_type' => 'post',
					'object_id'   => $postId,
					'url'         => is_string( $url ) ? $url : '',
					'page_model'  => array(),
					'last_error'  => $message,
				)
			);
			return;
		}

		$wpdb->update(
			Tables::pages(),
			array( 'last_error' => $message ),
			array( 'id' => (int) $existing['id'] )
		);
	}

	/**
	 * Stamp a successful live fetch.
	 */
	public function markFetched( int $pageId ): void {
		global $wpdb;

		$wpdb->update(
			Tables::pages(),
			array(
				'last_fetched_at' => current_time( 'mysql', true ),
				'last_error'      => null,
			),
			array( 'id' => $pageId )
		);
	}
}
