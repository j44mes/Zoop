<?php
/**
 * Site-wide priority list.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Recommendations;

use SEOStudio\Architecture\SiteSurvey;
use SEOStudio\Search\SearchRepository;
use SEOStudio\Storage\Tables;
use SEOStudio\Topics\OverlapReport;

/**
 * Reads the stored reports and orders them. It does not edit pages.
 */
final class PrioritiesReport {
	public function __construct( private readonly Priorities $priorities = new Priorities() ) {}

	/**
	 * Ordered review items. Editors and authors receive a filtered copy.
	 *
	 * @return array{items: list<array<string, mixed>>, notes: string[], limited: bool}
	 */
	public function report(): array {
		return $this->priorities->rank(
			( new SiteSurvey() )->report(),
			( new OverlapReport() )->report(),
			( new SearchRepository() )->allRows(),
			$this->verifiedNoindex()
		);
	}

	/**
	 * Published pages whose latest check found a noindex directive.
	 *
	 * Stale and unverified rows stay out. The permission filter runs later.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function verifiedNoindex(): array {
		global $wpdb;

		$pages = Tables::pages();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal table names; the status values are fixed.
		$rows = $wpdb->get_results(
			"SELECT pages.object_id AS post_id, posts.post_title AS title, pages.url AS url
			FROM {$pages} pages
			INNER JOIN {$wpdb->posts} posts ON posts.ID = pages.object_id
			WHERE pages.object_type = 'post'
				AND pages.evidence_ready = 1
				AND pages.onpage_state = 'current'
				AND pages.index_status = 'noindex'
			ORDER BY pages.id ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$ready = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$ready[] = array(
				'post_id' => (int) ( $row['post_id'] ?? 0 ),
				'title'   => (string) ( $row['title'] ?? '' ),
				'url'     => (string) ( $row['url'] ?? '' ),
				'fresh'   => true,
			);
		}

		return $ready;
	}
}
