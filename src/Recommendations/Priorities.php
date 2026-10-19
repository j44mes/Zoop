<?php
/**
 * Prioritised review items.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Recommendations;

use SEOStudio\Architecture\UrlKey;

/**
 * Orders stored architecture, overlap, and search evidence. It does not edit pages.
 */
final class Priorities {
	private const LIST_LIMIT = 40;

	/**
	 * Rank evidence the other reports already stored.
	 *
	 * @param array<string, mixed>       $architecture Public architecture report.
	 * @param array<string, mixed>       $overlap      Overlap groups and notes.
	 * @param list<array<string, mixed>> $search_rows  Imported query rows.
	 * @return array{items: list<array<string, mixed>>, notes: string[], limited: bool}
	 */
	public function rank( array $architecture, array $overlap, array $search_rows ): array {
		$demand       = $this->demand( $search_rows );
		$search_mode  = $this->searchMode( $search_rows );
		$items        = array();
		$demand_pages = array();
		$quiet_pages  = array();
		$reviews      = array();

		foreach ( $this->groups( $overlap ) as $group ) {
			$observation = (string) ( $group['observation'] ?? '' );
			if ( 'phrase' === $observation || 'topic' === $observation ) {
				$reviews[] = $this->overlapItem( 4, $group );
			}
		}

		$unresolved = $this->rows( $architecture, 'unresolved' );
		usort(
			$unresolved,
			static function ( array $left, array $right ): int {
				$by_count = (int) ( $right['occurrences'] ?? 0 ) <=> (int) ( $left['occurrences'] ?? 0 );
				return 0 !== $by_count ? $by_count : strcmp( (string) ( $left['href'] ?? '' ), (string) ( $right['href'] ?? '' ) );
			}
		);
		foreach ( $unresolved as $row ) {
			$items[] = $this->unresolvedItem( $row );
		}

		$complete = (bool) ( $architecture['coverage']['complete'] ?? false );
		if ( $complete ) {
			$this->splitPages( $this->rows( $architecture, 'orphans' ), 'orphan', $demand, $search_mode, $demand_pages, $quiet_pages );
		}
		$this->splitPages( $this->rows( $architecture, 'weak' ), 'weak', $demand, $search_mode, $demand_pages, $quiet_pages );

		usort(
			$demand_pages,
			static function ( array $left, array $right ): int {
				$by_count = (int) $right['impressions'] <=> (int) $left['impressions'];
				return 0 !== $by_count ? $by_count : strcasecmp( (string) $left['title'], (string) $right['title'] );
			}
		);
		foreach ( $demand_pages as $item ) {
			$items[] = $item;
		}
		foreach ( $quiet_pages as $item ) {
			$items[] = $item;
		}
		foreach ( $reviews as $item ) {
			$items[] = $item;
		}
		if ( 'absent' === $search_mode ) {
			$items[] = array(
				'priority'    => 4,
				'kind'        => 'search',
				'title'       => __( 'Import a Search Console file', 'seo-studio' ),
				'action'      => __( 'Import a performance export before treating demand as evidence.', 'seo-studio' ),
				'reason'      => __( 'Zoop is not connected to Search Console. Without an imported file, impressions cannot raise or lower a page. Even a future file would need dates and a page address before it could describe demand. Zoop does not edit the page.', 'seo-studio' ),
				'pages'       => array(),
				'impressions' => 0,
			);
		} elseif ( 'query_only' === $search_mode ) {
			$items[] = array(
				'priority'    => 4,
				'kind'        => 'search',
				'title'       => __( 'The Search Console file has no page addresses', 'seo-studio' ),
				'action'      => __( 'Import a file that includes the page column before comparing URLs.', 'seo-studio' ),
				'reason'      => __( 'This file has queries, clicks, and impressions, and no page addresses. Zoop cannot tell which URL received them. The counts are not verified commercial value and not evidence of lost traffic. Zoop does not edit the page.', 'seo-studio' ),
				'pages'       => array(),
				'impressions' => 0,
			);
		}

		$limited = count( $items ) > self::LIST_LIMIT;
		$items   = array_slice( $items, 0, self::LIST_LIMIT );
		$public  = array();
		foreach ( $items as $item ) {
			unset( $item['impressions'] );
			$public[] = $item;
		}

		return array(
			'items'   => $public,
			'limited' => $limited,
			'notes'   => $this->notes( $architecture, $search_mode, $limited ),
		);
	}

	/**
	 * Separate pages that have imported impressions from pages that do not.
	 *
	 * @param list<array<string, mixed>>                                        $rows         Architecture rows.
	 * @param string                                                            $status       Orphan or weak.
	 * @param array<string, array{impressions: int, clicks: int, queries: list<string>}> $demand Demand by address.
	 * @param string                                                            $search_mode  Absent, query-only, or page-level file.
	 * @param list<array<string, mixed>>                                        $with_demand  Pages with impressions.
	 * @param list<array<string, mixed>>                                        $without      Pages without impressions.
	 */
	private function splitPages( array $rows, string $status, array $demand, string $search_mode, array &$with_demand, array &$without ): void {
		foreach ( $rows as $row ) {
			$item = $this->pageItem( $row, $status, $demand, $search_mode );
			if ( 2 === (int) $item['priority'] ) {
				$with_demand[] = $item;
			} else {
				$without[] = $item;
			}
		}
	}

	/**
	 * One overlap group, without treating the phrase as proof of harm.
	 *
	 * @param array<string, mixed> $group Overlap group.
	 * @return array<string, mixed>
	 */
	private function overlapItem( int $priority, array $group ): array {
		$phrase = trim( (string) ( $group['phrase'] ?? '' ) );
		$pages  = array();
		$listed = is_array( $group['pages'] ?? null ) ? $group['pages'] : array();
		foreach ( $listed as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$pages[] = array(
				'post_id' => (int) ( $page['post_id'] ?? 0 ),
				'title'   => (string) ( $page['title'] ?? '' ),
				'url'     => (string) ( $page['url'] ?? '' ),
			);
		}
		$reason = trim( (string) ( $group['reason'] ?? '' ) );
		if ( ! str_contains( $reason, 'does not edit' ) ) {
			$reason = trim( $reason . ' ' . __( 'Zoop does not edit the pages.', 'seo-studio' ) );
		}

		return array(
			'priority'               => $priority,
			'kind'                   => 'overlap',
			'title'                  => '' !== $phrase ? $phrase : __( 'Shared wording', 'seo-studio' ),
			'action'                 => __( 'Compare what these pages are for. A shared phrase or topic is not proof that they compete.', 'seo-studio' ),
			'reason'                 => $reason,
			'observation'            => (string) ( $group['observation'] ?? '' ),
			'observation_confidence' => (string) ( $group['observation_confidence'] ?? 'insufficient' ),
			'conclusion'             => (string) ( $group['conclusion'] ?? 'investigation' ),
			'conclusion_confidence'  => (string) ( $group['conclusion_confidence'] ?? 'insufficient' ),
			'pages'                  => $pages,
			'impressions'            => 0,
		);
	}

	/**
	 * One internal address that matches no published page.
	 *
	 * @param array<string, mixed> $row Unresolved link.
	 * @return array<string, mixed>
	 */
	private function unresolvedItem( array $row ): array {
		$from        = trim( (string) ( $row['from_title'] ?? '' ) );
		$href        = (string) ( $row['href'] ?? '' );
		$occurrences = (int) ( $row['occurrences'] ?? 0 );
		$source      = '' !== $from ? $from : __( 'a stored page', 'seo-studio' );

		return array(
			'priority'    => 2,
			'kind'        => 'unresolved',
			'title'       => '' !== $href ? $href : __( 'Unresolved internal address', 'seo-studio' ),
			'action'      => __( 'Review this internal address. Zoop did not request it.', 'seo-studio' ),
			'reason'      => sprintf(
				/* translators: 1: occurrence count, 2: source page title. */
				__( 'This internal address appears %1$d times from %2$s and does not match a published page. Zoop did not request it. Zoop does not edit the page.', 'seo-studio' ),
				$occurrences,
				$source
			),
			'pages'       => array(
				array(
					'post_id' => $this->postId( (string) ( $row['from_id'] ?? '' ) ),
					'title'   => $source,
					'url'     => $href,
				),
			),
			'impressions' => 0,
		);
	}

	/**
	 * One orphan or weakly linked page.
	 *
	 * @param array<string, mixed>                                               $row    Architecture page.
	 * @param string                                                             $status Orphan or weak.
	 * @param array<string, array{impressions: int, clicks: int, queries: list<string>}> $demand Demand by address.
	 * @param string                                                             $search_mode Absent, query-only, or page-level file.
	 * @return array<string, mixed>
	 */
	private function pageItem( array $row, string $status, array $demand, string $search_mode ): array {
		$url      = (string) ( $row['url'] ?? '' );
		$identity = UrlKey::identity( UrlKey::absolute( $url, $url ) );
		$hit      = '' !== $identity && isset( $demand[ $identity ] ) ? $demand[ $identity ] : null;
		$count    = is_array( $hit ) ? (int) $hit['impressions'] : 0;
		$title    = trim( (string) ( $row['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = __( 'Untitled', 'seo-studio' );
		}
		$graph = 'orphan' === $status
			? __( 'This page has no contextual referring page in the stored crawl.', 'seo-studio' )
			: __( 'This page has one contextual referring page.', 'seo-studio' );

		if ( $count > 0 && is_array( $hit ) ) {
			$queries = array() !== $hit['queries'] ? implode( ', ', $hit['queries'] ) : __( 'this address', 'seo-studio' );
			$reason  = sprintf(
				/* translators: 1: link-graph sentence, 2: impression count, 3: query list. */
				__( '%1$s The imported file records %2$d impressions for %3$s. The file has no reporting dates or country and device split, so this count is not verified commercial value and not evidence of lost traffic. Zoop is not connected to Search Console. Zoop does not edit the page.', 'seo-studio' ),
				$graph,
				$count,
				$queries
			);
			$action   = __( 'Review this page. An imported impression count can help choose what to examine. It does not measure commercial value.', 'seo-studio' );
			$priority = 2;
		} elseif ( 'absent' === $search_mode ) {
			$reason   = $graph . ' ' . __( 'Demand is not known until a Search Console file is imported. Zoop does not edit the page.', 'seo-studio' );
			$action   = __( 'Review the link graph. Do not treat missing impressions as evidence.', 'seo-studio' );
			$priority = 3;
		} elseif ( 'query_only' === $search_mode ) {
			$reason   = $graph . ' ' . __( 'The imported file has queries and no page addresses, so Zoop cannot attach impressions to this URL. That is not zero demand and not lost traffic. Zoop does not edit the page.', 'seo-studio' );
			$action   = __( 'Review the link graph. A query-only file cannot rank this page by demand.', 'seo-studio' );
			$priority = 3;
		} else {
			$reason   = $graph . ' ' . __( 'No imported row names this address. A missing row is not zero demand, and the file has no dates or segmentation. Zoop does not edit the page.', 'seo-studio' );
			$action   = __( 'Review the link graph before looking for more referring pages.', 'seo-studio' );
			$priority = 3;
		}

		return array(
			'priority'    => $priority,
			'kind'        => 'page',
			'title'       => $title,
			'action'      => $action,
			'reason'      => $reason,
			'pages'       => array(
				array(
					'post_id' => (int) ( $row['post_id'] ?? 0 ),
					'title'   => $title,
					'url'     => $url,
				),
			),
			'impressions' => $count,
		);
	}

	/**
	 * Sum imported impressions for one published address.
	 *
	 * @param list<array<string, mixed>> $search_rows Imported query rows.
	 * @return array<string, array{impressions: int, clicks: int, queries: list<string>}>
	 */
	private function demand( array $search_rows ): array {
		$by = array();
		foreach ( $search_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$page     = (string) ( $row['page'] ?? '' );
			$identity = UrlKey::identity( UrlKey::absolute( $page, $page ) );
			if ( '' === $identity ) {
				continue;
			}
			if ( ! isset( $by[ $identity ] ) ) {
				$by[ $identity ] = array(
					'impressions' => 0,
					'clicks'      => 0,
					'queries'     => array(),
				);
			}
			$impressions                     = max( 0, (int) ( $row['impressions'] ?? 0 ) );
			$by[ $identity ]['impressions'] += $impressions;
			$by[ $identity ]['clicks']      += max( 0, (int) ( $row['clicks'] ?? 0 ) );
			$query                           = trim( (string) ( $row['query'] ?? '' ) );
			if ( '' !== $query ) {
				$by[ $identity ]['queries'][] = array(
					'query'       => $query,
					'impressions' => $impressions,
				);
			}
		}

		$ready = array();
		foreach ( $by as $identity => $entry ) {
			$queries = $entry['queries'];
			usort(
				$queries,
				static function ( array $left, array $right ): int {
					return (int) $right['impressions'] <=> (int) $left['impressions'];
				}
			);
			$labels = array();
			foreach ( array_slice( $queries, 0, 3 ) as $query ) {
				$labels[] = (string) $query['query'];
			}
			$ready[ $identity ] = array(
				'impressions' => (int) $entry['impressions'],
				'clicks'      => (int) $entry['clicks'],
				'queries'     => $labels,
			);
		}
		return $ready;
	}

	/**
	 * Whether the import can be attached to URLs.
	 *
	 * @param list<array<string, mixed>> $search_rows Imported query rows.
	 */
	private function searchMode( array $search_rows ): string {
		$seen  = false;
		$paged = false;
		foreach ( $search_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$seen = true;
			$page = (string) ( $row['page'] ?? '' );
			if ( '' !== UrlKey::identity( UrlKey::absolute( $page, $page ) ) ) {
				$paged = true;
			}
		}
		if ( ! $seen ) {
			return 'absent';
		}
		return $paged ? 'paged' : 'query_only';
	}

	/**
	 * Overlap groups that are arrays.
	 *
	 * @param array<string, mixed> $overlap Overlap report.
	 * @return list<array<string, mixed>>
	 */
	private function groups( array $overlap ): array {
		return $this->rows( $overlap, 'groups' );
	}

	/**
	 * List values that are arrays.
	 *
	 * @param array<string, mixed> $source Report.
	 * @return list<array<string, mixed>>
	 */
	private function rows( array $source, string $key ): array {
		$listed = $source[ $key ] ?? array();
		if ( ! is_array( $listed ) ) {
			return array();
		}
		$clean = array();
		foreach ( $listed as $row ) {
			if ( is_array( $row ) ) {
				$clean[] = $row;
			}
		}
		return $clean;
	}

	/**
	 * Numeric id at the end of an inventory key.
	 */
	private function postId( string $id ): int {
		if ( 1 === preg_match( '/(\d+)$/', $id, $matches ) ) {
			return (int) $matches[1];
		}
		return 0;
	}

	/**
	 * Sentences that keep the order honest.
	 *
	 * @param array<string, mixed> $architecture Architecture report.
	 * @return string[]
	 */
	private function notes( array $architecture, string $search_mode, bool $limited ): array {
		$search = __( 'No Search Console file has been imported, so demand is not used as evidence.', 'seo-studio' );
		if ( 'query_only' === $search_mode ) {
			$search = __( 'The imported file has no page addresses, so impressions cannot be attached to a URL. They are not verified commercial value or lost traffic.', 'seo-studio' );
		} elseif ( 'paged' === $search_mode ) {
			$search = __( 'Search figures come from a file you imported. They have no dates or segmentation, so they are not verified commercial value or evidence of lost traffic. Zoop is not connected to Search Console.', 'seo-studio' );
		}
		$notes = array(
			__( 'Zoop orders stored evidence for review. It does not edit pages, insert links, or change metadata.', 'seo-studio' ),
			__( 'A shared phrase is an observation. It is not an urgent cannibalisation problem.', 'seo-studio' ),
			__( 'Shared title words stay on the Overlap screen. They are not repeated here.', 'seo-studio' ),
			$search,
		);
		if ( ! (bool) ( $architecture['coverage']['complete'] ?? false ) ) {
			$notes[] = __( 'Orphan pages stay out of this list until every published page has a stored analysis.', 'seo-studio' );
		}
		if ( $limited || ! empty( $architecture['limited'] ) ) {
			$notes[] = __( 'Long lists are shortened. Higher priorities are kept first.', 'seo-studio' );
		}
		return $notes;
	}
}
