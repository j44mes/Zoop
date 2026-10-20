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
		$wpdb->update(
			Tables::pages(),
			array(
				'focus_keyphrase' => mb_substr( $phrase, 0, 255 ),
				'focus_hash'      => $hash,
			),
			array( 'id' => (int) $page['id'] )
		);
		$page['focus_hash'] = $hash;

		if ( '' === $hash ) {
			$this->applyScoreCache( (int) $page['id'], $this->rowHasModel( $page ) ? 'unscored' : 'not_analysed', null );
			return;
		}

		$scores = Tables::scores();
		$score  = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, score, denominator, rating, details FROM {$scores} WHERE page_id = %d AND keyphrase_hash = %s AND engine = 'onpage' ORDER BY id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
				(int) $page['id'],
				$hash
			),
			ARRAY_A
		);
		if ( ! is_array( $score ) ) {
			$this->applyScoreCache( (int) $page['id'], $this->rowHasModel( $page ) ? 'unscored' : 'not_analysed', null );
			return;
		}

		$details     = json_decode( (string) ( $score['details'] ?? '' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Reading stored JSON. There is no wp_json_decode.
		$details     = is_array( $details ) ? $details : array();
		$snapshot    = (string) ( $details['input_hash'] ?? '' );
		$version     = (int) ( $details['evidence_version'] ?? 0 );
		$source      = (string) ( $details['source_hash'] ?? '' );
		$current     = \SEOStudio\Content\SourceFingerprint::current( $postId );
		$same_model  = $version >= 2 && hash_equals( (string) ( $page['input_hash'] ?? '' ), $snapshot );
		$same_source = '' !== $source && '' !== $current && hash_equals( $source, $current );
		if ( $same_model && $same_source ) {
			$readiness = isset( $details['readiness'] ) && is_array( $details['readiness'] ) ? $details['readiness'] : array();
			$this->applyScoreCache( (int) $page['id'], 'current', $score, $readiness );
			return;
		}

		$this->applyScoreCache( (int) $page['id'], 'needs_reanalysis', null );
	}

	/**
	 * Point the page row at a new on-page score when it is for the current focus keyphrase.
	 */
	/**
	 * Point the page row at a new on-page score when it matches the current focus and snapshot.
	 *
	 * @param array<string, mixed> $readiness
	 */
	public function recordScore( int $pageId, string $keyphraseHash, int $scoreId, int $score, int $denominator, string $rating, string $inputHash, array $readiness = array(), string $sourceHash = '', bool $live = false ): void {
		global $wpdb;

		$pages = Tables::pages();
		$ready = $live && ! empty( $readiness['ready'] ) ? 1 : 0;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$pages} SET onpage_score_id = %d, onpage_score = %d, onpage_denominator = %d, onpage_rating = %s, onpage_state = 'current', evidence_ready = %d, checks_evaluated = %d, checks_applicable = %d, index_status = %s, index_source = %s, missing_evidence = %s WHERE id = %d AND focus_hash = %s AND focus_hash <> '' AND input_hash = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
				$scoreId,
				$score,
				$denominator,
				$rating,
				$ready,
				(int) ( $readiness['checks_evaluated'] ?? 0 ),
				(int) ( $readiness['checks_applicable'] ?? 0 ),
				(string) ( $readiness['index_status'] ?? 'unobserved' ),
				(string) ( $readiness['index_source'] ?? '' ),
				(string) ( $readiness['missing'] ?? '' ),
				$pageId,
				$keyphraseHash,
				$inputHash
			)
		);
		if ( ! $live ) {
			return;
		}

		$wpdb->update(
			$pages,
			array(
				'verified_source_hash'   => $sourceHash,
				'has_published_snapshot' => 1,
				'verified_at'            => current_time( 'mysql', true ),
			),
			array(
				'id'         => $pageId,
				'input_hash' => $inputHash,
			)
		);
	}

	/**
	 * Live rows verified before schema 6 are kept, and their numbers wait for a new check.
	 */
	public function retireUnverifiedLiveScores(): void {
		global $wpdb;

		$pages = Tables::pages();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name; no user input.
		$wpdb->query(
			"UPDATE {$pages} SET has_published_snapshot = 1, evidence_ready = 0, onpage_state = 'needs_reanalysis', onpage_score_id = NULL, onpage_score = NULL, onpage_denominator = NULL, onpage_rating = NULL WHERE render_mode = 'live' AND onpage_state = 'current'"
		);
		$wpdb->query( "UPDATE {$pages} SET has_published_snapshot = 1 WHERE render_mode = 'live'" );
		// phpcs:enable
	}

	/**
	 * Clear definitive readiness when the published inputs no longer match the last check.
	 */
	public function withholdIfSourceChanged( int $postId ): void {
		$page = $this->findByObject( 'post', $postId );
		if ( ! is_array( $page ) ) {
			return;
		}
		if ( 1 !== (int) ( $page['evidence_ready'] ?? 0 ) && 1 !== (int) ( $page['has_published_snapshot'] ?? 0 ) ) {
			return;
		}

		$verified = (string) ( $page['verified_source_hash'] ?? '' );
		$current  = \SEOStudio\Content\SourceFingerprint::current( $postId );
		if ( '' !== $verified && '' !== $current && hash_equals( $verified, $current ) ) {
			return;
		}

		$this->markSnapshotStale( (int) $page['id'] );
	}

	/**
	 * Theme, publisher, and site-setting changes affect every published snapshot.
	 */
	public function withholdPublishedSnapshots(): void {
		global $wpdb;

		$pages = Tables::pages();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name; no user input.
		$wpdb->query(
			"UPDATE {$pages} SET evidence_ready = 0, onpage_state = IF(onpage_state = 'current', 'needs_reanalysis', onpage_state), onpage_score_id = IF(onpage_state = 'current', NULL, onpage_score_id), onpage_score = IF(onpage_state = 'current', NULL, onpage_score), onpage_denominator = IF(onpage_state = 'current', NULL, onpage_denominator), onpage_rating = IF(onpage_state = 'current', NULL, onpage_rating), index_status = IF(index_status IN ('indexable', 'noindex', 'site_discouraged'), 'unobserved', index_status) WHERE evidence_ready = 1 OR (has_published_snapshot = 1 AND onpage_state = 'current')"
		);
		// phpcs:enable
	}

	private function markSnapshotStale( int $pageId ): void {
		global $wpdb;

		$page = $this->find( $pageId );
		if ( ! is_array( $page ) ) {
			return;
		}

		$data = array(
			'evidence_ready' => 0,
			'index_status'   => 'unobserved',
			'index_source'   => '',
		);
		if ( 'current' === (string) ( $page['onpage_state'] ?? '' ) ) {
			$data['onpage_state']       = 'needs_reanalysis';
			$data['onpage_score_id']    = null;
			$data['onpage_score']       = null;
			$data['onpage_denominator'] = null;
			$data['onpage_rating']      = null;
		}

		$wpdb->update( Tables::pages(), $data, array( 'id' => $pageId ) );
	}

	/**
	 * Mark every stored on-page result as needing the evidence-version-2 rules.
	 *
	 * One upgrade statement. Later list and summary queries read onpage_state and do not join scores.
	 */
	public function retireCachedScores(): void {
		global $wpdb;

		$pages  = Tables::pages();
		$scores = Tables::scores();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table names; no user input.
		$wpdb->query(
			"UPDATE {$pages} pages
			SET onpage_state = CASE
				WHEN EXISTS (SELECT 1 FROM {$scores} scores WHERE scores.page_id = pages.id AND scores.engine = 'onpage') THEN 'needs_reanalysis'
				WHEN pages.page_model IS NOT NULL AND pages.page_model <> '' AND pages.page_model <> '[]' AND pages.page_model <> 'null' AND pages.page_model <> '{}' THEN 'unscored'
				ELSE 'not_analysed'
			END,
			onpage_score_id = NULL, onpage_score = NULL, onpage_denominator = NULL, onpage_rating = NULL"
		);
		// phpcs:enable
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
			SET pages.onpage_score_id = IF(JSON_EXTRACT(scores.details, '$.evidence_version') = 2 AND JSON_UNQUOTE(JSON_EXTRACT(scores.details, '$.input_hash')) = pages.input_hash, scores.id, NULL),
				pages.onpage_score = IF(JSON_EXTRACT(scores.details, '$.evidence_version') = 2 AND JSON_UNQUOTE(JSON_EXTRACT(scores.details, '$.input_hash')) = pages.input_hash, scores.score, NULL),
				pages.onpage_denominator = IF(JSON_EXTRACT(scores.details, '$.evidence_version') = 2 AND JSON_UNQUOTE(JSON_EXTRACT(scores.details, '$.input_hash')) = pages.input_hash, scores.denominator, NULL),
				pages.onpage_rating = IF(JSON_EXTRACT(scores.details, '$.evidence_version') = 2 AND JSON_UNQUOTE(JSON_EXTRACT(scores.details, '$.input_hash')) = pages.input_hash, scores.rating, NULL),
				pages.onpage_state = IF(JSON_EXTRACT(scores.details, '$.evidence_version') = 2 AND JSON_UNQUOTE(JSON_EXTRACT(scores.details, '$.input_hash')) = pages.input_hash, 'current', 'needs_reanalysis')
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

		$existing = $this->findByObject( 'post', $postId );
		$model    = $merged->toArray();
		if ( 'live' === $merged->renderMode && function_exists( 'get_post' ) ) {
			$model['link_source_hash'] = \SEOStudio\Architecture\LinkSource::current( $postId );
		}
		$id = $this->save(
			array(
				'object_type'      => 'post',
				'object_id'        => $postId,
				'url'              => $merged->url,
				'input_hash'       => $inputHash,
				'page_model'       => $model,
				'render_mode'      => $merged->renderMode,
				'content_selector' => $merged->contentSelectorUsed,
				'is_indexable'     => Indexability::forPost( $postId, $merged ),
			)
		);
		if ( ! is_array( $existing ) || (string) $existing['input_hash'] === $inputHash ) {
			return $id;
		}

		$state = (string) ( $existing['onpage_state'] ?? 'not_analysed' );
		if ( 'current' === $state || 'needs_reanalysis' === $state ) {
			$this->applyScoreCache( $id, 'needs_reanalysis', null );
		} elseif ( 'not_analysed' === $state ) {
			$this->applyScoreCache( $id, 'unscored', null );
		}

		return $id;
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
				pages.onpage_rating AS onpage_rating, pages.onpage_state AS onpage_state,
				pages.evidence_ready AS evidence_ready, pages.checks_evaluated AS checks_evaluated,
				pages.checks_applicable AS checks_applicable, pages.index_status AS index_status,
				pages.index_source AS index_source, pages.missing_evidence AS missing_evidence,
				pages.has_published_snapshot AS has_published_snapshot, pages.verified_at AS verified_at,
				(SELECT details FROM {$scores} detail WHERE detail.id = pages.onpage_score_id) AS onpage_details,
				(SELECT rating FROM {$scores} seo_scores WHERE seo_scores.page_id = pages.id AND seo_scores.engine = 'seo'
					AND pages.focus_hash <> '' AND seo_scores.keyphrase_hash = pages.focus_hash
					ORDER BY seo_scores.id DESC LIMIT 1) AS seo_rating,
				(SELECT rating FROM {$scores} read_scores WHERE read_scores.page_id = pages.id AND read_scores.engine = 'readability'
					AND pages.focus_hash <> '' AND read_scores.keyphrase_hash = pages.focus_hash
					ORDER BY read_scores.id DESC LIMIT 1) AS readability_rating"
			: 'pages.id, pages.object_id, pages.onpage_score AS onpage_score, pages.onpage_denominator AS onpage_denominator, pages.onpage_rating AS onpage_rating, pages.onpage_state AS onpage_state, pages.evidence_ready AS evidence_ready, pages.checks_evaluated AS checks_evaluated, pages.checks_applicable AS checks_applicable, pages.index_status AS index_status, pages.index_source AS index_source, pages.missing_evidence AS missing_evidence, pages.has_published_snapshot AS has_published_snapshot, pages.verified_at AS verified_at';
		$sql     = "SELECT {$columns} {$query['sql']} ORDER BY {$orderby} {$order}, pages.id {$order}";
		$sql    .= ' LIMIT ' . max( 1, min( 200, $limit ) ) . ' OFFSET ' . max( 0, $offset );

		$rows = $wpdb->get_results( $this->prepare( $sql, $query['values'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared by prepare().
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Total and dashboard summary for the same filters, in one query.
	 *
	 * @param array<string, mixed> $filters
	 * @return array{total: int, scored: int, unscored: int, needs_reanalysis: int, not_analysed: int, failed: int, red: int, orange: int, green: int, average: ?int}
	 */
	public function aggregate( array $filters ): array {
		global $wpdb;

		$query = $this->listQuery( $filters );
		$sql   = "SELECT COUNT(*) AS total,
				SUM(pages.onpage_state = 'current' AND pages.evidence_ready = 1 AND pages.onpage_denominator > 0) AS scored,
				SUM(pages.onpage_state = 'unscored') AS unscored,
				SUM(pages.onpage_state = 'needs_reanalysis') AS needs_reanalysis,
				SUM(pages.onpage_state = 'not_analysed') AS not_analysed,
				SUM(pages.last_error IS NOT NULL AND pages.last_error <> '') AS failed,
				SUM(pages.onpage_state = 'current' AND pages.evidence_ready = 1 AND pages.onpage_rating = 'red') AS red,
				SUM(pages.onpage_state = 'current' AND pages.evidence_ready = 1 AND pages.onpage_rating = 'orange') AS orange,
				SUM(pages.onpage_state = 'current' AND pages.evidence_ready = 1 AND pages.onpage_rating = 'green') AS green,
				AVG(CASE WHEN pages.onpage_state = 'current' AND pages.evidence_ready = 1 AND pages.onpage_denominator > 0 THEN pages.onpage_score * 100 / pages.onpage_denominator END) AS average
			{$query['sql']}";
		$row   = $wpdb->get_row( $this->prepare( $sql, $query['values'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared by prepare().
		$row   = is_array( $row ) ? $row : array();

		return array(
			'total'            => (int) ( $row['total'] ?? 0 ),
			'scored'           => (int) ( $row['scored'] ?? 0 ),
			'unscored'         => (int) ( $row['unscored'] ?? 0 ),
			'needs_reanalysis' => (int) ( $row['needs_reanalysis'] ?? 0 ),
			'not_analysed'     => (int) ( $row['not_analysed'] ?? 0 ),
			'failed'           => (int) ( $row['failed'] ?? 0 ),
			'red'              => (int) ( $row['red'] ?? 0 ),
			'orange'           => (int) ( $row['orange'] ?? 0 ),
			'green'            => (int) ( $row['green'] ?? 0 ),
			'average'          => isset( $row['average'] ) ? (int) round( (float) $row['average'] ) : null,
		);
	}

	/**
	 * Definitive pages in this authorised set whose stored checks include a fail or partial result.
	 *
	 * Called for the suggestions request only, so the dashboard list query stays unchanged.
	 *
	 * @param array<string, mixed> $filters Authorised list filters.
	 */
	public function actionableCount( array $filters ): int {
		global $wpdb;

		$query  = $this->listQuery( $filters );
		$scores = Tables::scores();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Internal table name. Placeholders are prepared with the list filters.
		$sql = "SELECT COUNT(*) {$query['sql']} AND pages.onpage_state = 'current' AND pages.evidence_ready = 1 AND EXISTS (
			SELECT 1 FROM {$scores} findings
			WHERE findings.id = pages.onpage_score_id
			AND (findings.details LIKE '%\"status\":\"fail\"%' OR findings.details LIKE '%\"status\":\"partial\"%')
		)";
		return (int) $wpdb->get_var( $this->prepare( $sql, $query['values'] ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
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
			$where[]  = "pages.onpage_state = 'current' AND pages.evidence_ready = 1 AND pages.onpage_rating = %s";
			$values[] = $filters['rating'];
		}

		if ( isset( $filters['score_min'] ) && is_numeric( $filters['score_min'] ) ) {
			$where[]  = "pages.onpage_state = 'current' AND pages.evidence_ready = 1 AND pages.onpage_score >= %d";
			$values[] = (int) $filters['score_min'];
		}

		if ( isset( $filters['score_max'] ) && is_numeric( $filters['score_max'] ) ) {
			$where[]  = "pages.onpage_state = 'current' AND pages.evidence_ready = 1 AND pages.onpage_score <= %d";
			$values[] = (int) $filters['score_max'];
		}

		if ( array_key_exists( 'has_keyphrase', $filters ) && null !== $filters['has_keyphrase'] ) {
			$where[] = ! empty( $filters['has_keyphrase'] )
				? "pages.focus_keyphrase <> ''"
				: "pages.focus_keyphrase = ''";
		}

		if ( 'needs_reanalysis' === ( $filters['score_state'] ?? '' ) ) {
			$where[] = "pages.onpage_state = 'needs_reanalysis'";
		}

		// Same predicate as aggregate()'s scored column. Rows with a stored model stay included.
		if ( 'unchecked' === ( $filters['score_state'] ?? '' ) ) {
			$where[] = "NOT (pages.onpage_state = 'current' AND pages.evidence_ready = 1 AND pages.onpage_denominator > 0)";
		}

		if ( 'noindex' === ( $filters['visibility'] ?? '' ) ) {
			$where[] = "pages.evidence_ready = 1 AND pages.onpage_state = 'current' AND pages.index_status = 'noindex'";
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
	 * Links stored for every post row, keyed by object id.
	 *
	 * A row with a page model counts as analysed. The graph reads links only, not the body text.
	 *
	 * @return array<int, array{render_mode: string, analysed: bool, h1: string, links: array<int, array{href: string, anchor: string, kind: string}>, model: array<string, mixed>}>
	 */
	public function storedLinks(): array {
		global $wpdb;

		$table = Tables::pages();
		$rows  = $wpdb->get_results( "SELECT object_id, render_mode, page_model FROM {$table} WHERE object_type = 'post'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table name.
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$stored = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$object_id = (int) ( $row['object_id'] ?? 0 );
			if ( $object_id <= 0 ) {
				continue;
			}
			$decoded = json_decode( (string) ( $row['page_model'] ?? '' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Reading stored JSON.
			$h1      = '';
			if ( is_array( $decoded ) && isset( $decoded['h1'] ) && is_array( $decoded['h1'] ) && isset( $decoded['h1'][0] ) ) {
				$h1 = trim( (string) $decoded['h1'][0] );
			}
			$links = array();
			if ( is_array( $decoded ) && isset( $decoded['links'] ) && is_array( $decoded['links'] ) ) {
				foreach ( $decoded['links'] as $link ) {
					if ( ! is_array( $link ) ) {
						continue;
					}
					$links[] = array(
						'href'   => (string) ( $link['href'] ?? '' ),
						'anchor' => (string) ( $link['anchor'] ?? '' ),
						'kind'   => (string) ( $link['kind'] ?? '' ),
					);
				}
			}
			$stored[ $object_id ] = array(
				'render_mode' => (string) ( $row['render_mode'] ?? '' ),
				'analysed'    => is_array( $decoded ),
				'h1'          => $h1,
				'links'       => $links,
				'model'       => is_array( $decoded ) ? $decoded : array(),
			);
		}

		return $stored;
	}

	/**
	 * Write the cached on-page score, or clear it for a state that is not current.
	 *
	 * @param array<string, mixed>|null $score     Score row, when the state is current.
	 * @param array<string, mixed>      $readiness Readiness columns stored with that score.
	 */
	private function applyScoreCache( int $pageId, string $state, ?array $score, array $readiness = array() ): void {
		global $wpdb;

		$data = array(
			'onpage_state'       => $state,
			'onpage_score_id'    => null,
			'onpage_score'       => null,
			'onpage_denominator' => null,
			'onpage_rating'      => null,
			'evidence_ready'     => 0,
		);
		if ( 'current' === $state && is_array( $score ) ) {
			$data['onpage_score_id']    = (int) $score['id'];
			$data['onpage_score']       = (int) $score['score'];
			$data['onpage_denominator'] = (int) $score['denominator'];
			$data['onpage_rating']      = (string) $score['rating'];
			$data['evidence_ready']     = ! empty( $readiness['ready'] ) ? 1 : 0;
			$data['checks_evaluated']   = (int) ( $readiness['checks_evaluated'] ?? 0 );
			$data['checks_applicable']  = (int) ( $readiness['checks_applicable'] ?? 0 );
			$data['index_status']       = (string) ( $readiness['index_status'] ?? 'unobserved' );
			$data['index_source']       = (string) ( $readiness['index_source'] ?? '' );
			$data['missing_evidence']   = (string) ( $readiness['missing'] ?? '' );
		}

		$wpdb->update( Tables::pages(), $data, array( 'id' => $pageId ) );
	}

	/**
	 * A stored page model is a completed render. An error row stores an empty list.
	 *
	 * @param array<string, mixed> $page
	 */
	private function rowHasModel( array $page ): bool {
		$raw = trim( (string) ( $page['page_model'] ?? '' ) );
		return '' !== $raw && '[]' !== $raw && 'null' !== $raw && '{}' !== $raw;
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
