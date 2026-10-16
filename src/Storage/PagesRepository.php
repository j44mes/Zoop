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
				'page_model'       => array(
					'title_tag'        => $model->titleTag,
					'meta_description' => $model->metaDescription,
					'body_text'        => $model->bodyText,
					'h1'               => $model->h1,
					'headings'         => $model->headings,
				),
				'render_mode'      => $model->renderMode,
				'content_selector' => $model->contentSelectorUsed,
				'is_indexable'     => true,
			)
		);
	}
}
