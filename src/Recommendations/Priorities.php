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
	 * @param list<array<string, mixed>> $search_rows       Imported query rows.
	 * @param int                        $hiddenFromSearch Fresh verified noindex pages the viewer may edit.
	 * @return array{items: list<array<string, mixed>>, notes: string[], limited: bool}
	 */
	public function rank( array $architecture, array $overlap, array $search_rows, int $hiddenFromSearch = 0 ): array {
		$demand       = $this->demand( $search_rows );
		$coverage     = $this->searchCoverage( $search_rows );
		$search_mode  = $coverage['mode'];
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
		foreach ( $this->rows( $architecture, 'unverified' ) as $row ) {
			$items[] = $this->unverifiedItem( $row );
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

		$see_all = ! function_exists( 'current_user_can' ) || current_user_can( 'manage_options' );
		$items   = $this->actionable(
			$items,
			$see_all,
			static function ( int $id ): bool {
				return function_exists( 'current_user_can' ) && current_user_can( 'edit_post', $id );
			}
		);
		$hidden  = $this->hiddenFromSearchItem( $hiddenFromSearch );
		$room    = self::LIST_LIMIT - ( null === $hidden ? 0 : 1 );
		$limited = count( $items ) > $room;
		$items   = array_slice( $items, 0, max( 0, $room ) );
		if ( null !== $hidden ) {
			$items[] = $hidden;
		}
		$public = array();
		foreach ( $items as $item ) {
			unset( $item['impressions'] );
			$public[] = $item;
		}

		return array(
			'items'   => $public,
			'limited' => $limited,
			'notes'   => $this->notes( $architecture, $coverage, $limited ),
		);
	}

	/**
	 * Keep findings the current user can act on, before the display limit.
	 *
	 * Whole-site evidence is already reflected in the items. This only removes
	 * or redacts pages the user cannot edit. Administrators receive the list unchanged.
	 *
	 * @param list<array<string, mixed>> $items   Ranked items, before the display limit.
	 * @param callable(int): bool        $canEdit Whether the user can edit one post.
	 * @return list<array<string, mixed>>
	 */
	public function actionable( array $items, bool $seeAll, callable $canEdit ): array {
		if ( $seeAll ) {
			return $items;
		}

		$visible = array();
		foreach ( $items as $item ) {
			$next = $this->redactItem( $item, $canEdit );
			if ( null !== $next ) {
				$visible[] = $next;
			}
		}

		return $visible;
	}

	/**
	 * Drop site-wide actions and pages this user cannot edit.
	 *
	 * @param array<string, mixed> $item    One ranked item.
	 * @param callable(int): bool  $canEdit Whether the user can edit one post.
	 * @return array<string, mixed>|null
	 */
	private function redactItem( array $item, callable $canEdit ): ?array {
		if ( 'search' === (string) ( $item['kind'] ?? '' ) ) {
			return null;
		}

		$pages = is_array( $item['pages'] ?? null ) ? $item['pages'] : array();
		if ( array() === $pages ) {
			return null;
		}

		$kept   = array();
		$hidden = false;
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$id = (int) ( $page['post_id'] ?? 0 );
			if ( $id > 0 && $canEdit( $id ) ) {
				$kept[] = array(
					'post_id' => $id,
					'title'   => (string) ( $page['title'] ?? '' ),
					'url'     => (string) ( $page['url'] ?? '' ),
				);
				continue;
			}
			$hidden = true;
		}

		if ( array() === $kept ) {
			return null;
		}
		if ( $hidden ) {
			$kept[] = array(
				'post_id'  => 0,
				'title'    => '',
				'url'      => '',
				'redacted' => true,
			);
		}
		$item['pages'] = $kept;
		if ( isset( $item['candidates'] ) && is_array( $item['candidates'] ) ) {
			$candidates = array();
			foreach ( $item['candidates'] as $candidate ) {
				if ( ! is_array( $candidate ) ) {
					continue;
				}
				$id = (int) ( $candidate['post_id'] ?? 0 );
				if ( $id > 0 && $canEdit( $id ) ) {
					$candidates[] = $candidate;
					continue;
				}
				$hidden = true;
			}
			$item['candidates'] = $candidates;
			if ( $hidden ) {
				$item['action'] = $this->candidateAction( $candidates );
			}
		}
		if ( $hidden ) {
			$item['reason']   = __( 'Part of this finding refers to a page you cannot open. Its title and address are hidden. Zoop does not edit the page.', 'seo-studio' );
			$item['evidence'] = __( 'The link count includes the whole published site. Hidden pages are not named.', 'seo-studio' );
		}

		return $item;
	}

	/**
	 * One finding for every fresh verified noindex page. It does not list those pages.
	 *
	 * @return array<string, mixed>|null
	 */
	private function hiddenFromSearchItem( int $count ): ?array {
		if ( $count < 1 ) {
			return null;
		}

		$href = 'admin.php?page=seostudio&section=pages&visibility=noindex';
		if ( function_exists( 'admin_url' ) ) {
			$href = admin_url( $href );
		}

		return array(
			'priority'    => 4,
			'kind'        => 'visibility',
			'title'       => sprintf(
				/* translators: %d: number of pages with a fresh noindex directive. */
				_n(
					'%d page is hidden from search.',
					'%d pages are hidden from search.',
					$count,
					'seo-studio'
				),
				$count
			),
			'action'      => __( 'Review these pages. Hiding a page from search may be intentional.', 'seo-studio' ),
			'reason'      => __( 'This count uses the latest published-page check only. The content score is separate and is not reduced. Zoop does not change the robots directive.', 'seo-studio' ),
			'href'        => $href,
			'pages'       => array(),
			'impressions' => 0,
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
	 * Suggested sources the viewer can still open.
	 *
	 * @param list<array<string, mixed>> $candidates Remaining candidates.
	 */
	private function candidateAction( array $candidates ): string {
		if ( array() === $candidates ) {
			return __( 'No stored topic or keyphrase matches this page that you can open.', 'seo-studio' );
		}
		$titles = array();
		foreach ( $candidates as $candidate ) {
			$title = trim( (string) ( $candidate['title'] ?? '' ) );
			if ( '' !== $title ) {
				$titles[] = $title;
			}
		}
		return sprintf(
			/* translators: %s: comma-separated page titles that share a stored topic or keyphrase. */
			__( 'Review these stored matches as potential link sources: %s.', 'seo-studio' ),
			implode( ', ', $titles )
		);
	}

	/**
	 * One same-site address that WordPress did not classify. It is not a confirmed broken link.
	 *
	 * @param array<string, mixed> $row Unverified link.
	 * @return array<string, mixed>
	 */
	private function unverifiedItem( array $row ): array {
		$from        = trim( (string) ( $row['from_title'] ?? '' ) );
		$href        = (string) ( $row['href'] ?? '' );
		$occurrences = (int) ( $row['occurrences'] ?? 0 );
		$source      = '' !== $from ? $from : __( 'a stored page', 'seo-studio' );
		$evidence    = trim( (string) ( $row['reason'] ?? '' ) );
		if ( '' === $evidence ) {
			$evidence = __( 'No local WordPress route matched this address. It is not a confirmed broken link.', 'seo-studio' );
		}

		return array(
			'priority'    => 4,
			'kind'        => 'unverified',
			'title'       => '' !== $href ? $href : __( 'Unverified address', 'seo-studio' ),
			'action'      => __( 'Review this address. Zoop did not confirm that it is broken and does not edit the page.', 'seo-studio' ),
			'reason'      => sprintf(
				/* translators: 1: occurrence count, 2: source page title. */
				__( 'This address appears %1$d times from %2$s. It is not a confirmed broken link. Zoop did not request it. Zoop does not edit the page.', 'seo-studio' ),
				$occurrences,
				$source
			),
			'observation' => __( 'Unverified destination.', 'seo-studio' ),
			'evidence'    => $evidence,
			'confidence'  => 'limited',
			'verify'      => __( 'Check the published page after the address is updated.', 'seo-studio' ),
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

		$resolution = (string) ( $row['resolution'] ?? 'unresolved' );
		$reason     = 'missing' === $resolution
			? sprintf(
				/* translators: 1: occurrence count, 2: source page title. */
				__( 'This address appears %1$d times from %2$s and WordPress shows the destination is gone. Zoop did not request it. Zoop does not edit the page.', 'seo-studio' ),
				$occurrences,
				$source
			)
			: sprintf(
				/* translators: 1: occurrence count, 2: source page title. */
				__( 'This address appears %1$d times from %2$s and does not match a published page. It is not a confirmed broken link. Zoop did not request it. Zoop does not edit the page.', 'seo-studio' ),
				$occurrences,
				$source
			);
		return array(
			'priority'    => 2,
			'kind'        => 'unresolved',
			'title'       => '' !== $href ? $href : __( 'Unverified internal address', 'seo-studio' ),
			'action'      => 'missing' === $resolution
				? __( 'Review this address. WordPress shows the destination is gone.', 'seo-studio' )
				: __( 'Review this address. Zoop could not classify it and did not request it.', 'seo-studio' ),
			'reason'      => $reason,
			'observation' => 'missing' === $resolution
				? __( 'Confirmed missing destination.', 'seo-studio' )
				: __( 'Unverified destination.', 'seo-studio' ),
			'evidence'    => (string) ( $row['reason'] ?? '' ),
			'confidence'  => 'missing' === $resolution ? 'high' : 'limited',
			'verify'      => __( 'Check the published page after the address is updated.', 'seo-studio' ),
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
		$graph     = 'orphan' === $status
			? __( 'This page has no contextual referring page in the stored crawl.', 'seo-studio' )
			: __( 'This page has one contextual referring page.', 'seo-studio' );
		$explained = '' !== (string) ( $row['observation'] ?? '' ) && '' !== (string) ( $row['evidence'] ?? '' );

		if ( $count > 0 && is_array( $hit ) ) {
			$queries       = array() !== $hit['queries'] ? implode( ', ', $hit['queries'] ) : __( 'this address', 'seo-studio' );
			$demand_reason = sprintf(
				/* translators: 1: impression count, 2: query list. */
				__( 'The imported file records %1$d impressions for %2$s. The file has no reporting dates or country and device split, so this count is not verified commercial value and not evidence of lost traffic. Zoop is not connected to Search Console. Zoop does not edit the page.', 'seo-studio' ),
				$count,
				$queries
			);
			$reason   = $explained ? $demand_reason : $graph . ' ' . $demand_reason;
			$action   = __( 'Review this page. An imported impression count can help choose what to examine. It does not measure commercial value.', 'seo-studio' );
			$priority = 2;
		} elseif ( 'absent' === $search_mode ) {
			$reason   = ( $explained ? '' : $graph . ' ' ) . __( 'Demand is not known until a Search Console file is imported. Zoop does not edit the page.', 'seo-studio' );
			$action   = __( 'Review the link graph. Do not treat missing impressions as evidence.', 'seo-studio' );
			$priority = 3;
		} elseif ( 'query_only' === $search_mode ) {
			$reason   = ( $explained ? '' : $graph . ' ' ) . __( 'The imported file has queries and no page addresses, so Zoop cannot attach impressions to this URL. That is not zero demand and not lost traffic. Zoop does not edit the page.', 'seo-studio' );
			$action   = __( 'Review the link graph. A query-only file cannot rank this page by demand.', 'seo-studio' );
			$priority = 3;
		} else {
			$reason   = ( $explained ? '' : $graph . ' ' ) . __( 'No imported row names this address. A missing row is not zero demand, and the file has no dates or segmentation. Zoop does not edit the page.', 'seo-studio' );
			$action   = __( 'Review the link graph before looking for more referring pages.', 'seo-studio' );
			$priority = 3;
		}

		$item = array(
			'priority'    => $priority,
			'kind'        => 'page',
			'title'       => $title,
			'action'      => '' !== (string) ( $row['action'] ?? '' ) ? (string) $row['action'] : $action,
			'reason'      => $reason,
			'observation' => (string) ( $row['observation'] ?? '' ),
			'evidence'    => (string) ( $row['evidence'] ?? '' ),
			'confidence'  => (string) ( $row['confidence'] ?? '' ),
			'verify'      => (string) ( $row['verify'] ?? '' ),
			'candidates'  => is_array( $row['candidates'] ?? null ) ? $row['candidates'] : array(),
			'pages'       => array(
				array(
					'post_id' => (int) ( $row['post_id'] ?? 0 ),
					'title'   => $title,
					'url'     => $url,
				),
			),
			'impressions' => $count,
		);
		return $item;
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
	 * Whether the import can be attached to URLs, and how many rows can.
	 *
	 * @param list<array<string, mixed>> $search_rows Imported query rows.
	 * @return array{mode: string, rows: int, with_page: int, without_page: int}
	 */
	private function searchCoverage( array $search_rows ): array {
		$rows = 0;
		$with = 0;
		foreach ( $search_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			++$rows;
			$page = (string) ( $row['page'] ?? '' );
			if ( '' !== UrlKey::identity( UrlKey::absolute( $page, $page ) ) ) {
				++$with;
			}
		}
		if ( 0 === $rows ) {
			$mode = 'absent';
		} else {
			$mode = $with > 0 ? 'paged' : 'query_only';
		}

		return array(
			'mode'         => $mode,
			'rows'         => $rows,
			'with_page'    => $with,
			'without_page' => $rows - $with,
		);
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
	 * @param array<string, mixed>                                   $architecture Architecture report.
	 * @param array{mode: string, rows: int, with_page: int, without_page: int} $coverage Search-file coverage.
	 * @return string[]
	 */
	private function notes( array $architecture, array $coverage, bool $limited ): array {
		$search_mode = $coverage['mode'];
		$search      = __( 'No Search Console file has been imported, so demand is not used as evidence.', 'seo-studio' );
		if ( 'absent' !== $search_mode ) {
			$search = sprintf(
				/* translators: 1: imported row count, 2: rows with a usable page address, 3: rows without one. */
				__( 'Rows imported: %1$d. Rows with a usable page address: %2$d. Rows without a usable page address: %3$d. Rows without a page address are not attached to a URL. The counts have no dates or segmentation, so they are not verified commercial value or evidence of lost traffic. Zoop is not connected to Search Console.', 'seo-studio' ),
				$coverage['rows'],
				$coverage['with_page'],
				$coverage['without_page']
			);
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
