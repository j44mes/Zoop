<?php
/**
 * Deterministic internal-link graph.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Architecture;

/**
 * Counts referring pages separately from repeated links, and withholds orphans when the crawl is incomplete.
 */
final class Graph {
	private const LIST_LIMIT = 50;

	/**
	 * Inventory pages keyed by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $nodes = array();

	/**
	 * Normalised address to the first inventory page that uses it.
	 *
	 * @var array<string, string>
	 */
	private array $byIdentity = array();

	/**
	 * Occurrences of each source page, split by link kind.
	 *
	 * @var array<string, array<string, array<string, int>>>
	 */
	private array $incoming = array();

	/**
	 * Inventory targets reached from each source.
	 *
	 * @var array<string, array<string, true>>
	 */
	private array $outgoing = array();

	/**
	 * Internal addresses that match no inventory page.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $unresolved = array();

	/**
	 * Site address used to decide which links are internal.
	 *
	 * @var string
	 */
	private string $home = '';

	/**
	 * Inventory id of the front page, when one is published.
	 *
	 * @var string
	 */
	private string $frontId = '';

	/**
	 * True when every published page in view has a stored analysis.
	 *
	 * @var bool
	 */
	private bool $complete = false;

	/**
	 * Published pages in view that have no stored analysis.
	 *
	 * @var int
	 */
	private int $missing = 0;

	/**
	 * True when this account cannot see every published page.
	 *
	 * @var bool
	 */
	private bool $withheld = false;

	/**
	 * Published pages that share an address with an earlier page.
	 *
	 * @var int
	 */
	private int $collisions = 0;

	/**
	 * Build the public report and the per-page stats used by later reports.
	 *
	 * @param list<array<string, mixed>> $pages
	 * @param list<array<string, mixed>> $navigation
	 * @param array<string, mixed>       $context
	 * @return array{report: array<string, mixed>, pages: array<string, array<string, mixed>>}
	 */
	public function evaluate( array $pages, array $navigation, array $context ): array {
		$this->reset();
		$this->home     = (string) ( $context['home'] ?? '' );
		$this->complete = (bool) ( $context['coverage_complete'] ?? false );
		$this->missing  = max( 0, (int) ( $context['missing'] ?? 0 ) );
		$this->withheld = (bool) ( $context['withheld'] ?? false );
		$this->index( $pages );
		$this->chooseFront( (string) ( $context['front_id'] ?? '' ) );

		foreach ( $this->nodes as $id => $node ) {
			if ( empty( $node['analysed'] ) ) {
				continue;
			}
			$this->absorb( (string) $id, (string) $node['title'], (string) $node['url'], (array) $node['links'], '' );
		}
		$this->absorb( 'site:navigation', __( 'Site navigation', 'seo-studio' ), $this->home, $navigation, 'template' );

		$stats = $this->stats();
		$depth = $this->depths( $navigation );
		$rows  = $this->rows( $stats, $depth );

		return array(
			'report' => $this->report( $rows, $depth, count( $navigation ) ),
			'pages'  => $rows,
		);
	}

	private function reset(): void {
		$this->nodes      = array();
		$this->byIdentity = array();
		$this->incoming   = array();
		$this->outgoing   = array();
		$this->unresolved = array();
		$this->frontId    = '';
		$this->collisions = 0;
	}

	/**
	 * Index inventory pages by their normalised address.
	 *
	 * @param list<array<string, mixed>> $pages
	 */
	private function index( array $pages ): void {
		foreach ( $pages as $page ) {
			if ( ! is_array( $page ) ) {
				continue;
			}
			$id = (string) ( $page['id'] ?? '' );
			if ( '' === $id || isset( $this->nodes[ $id ] ) ) {
				continue;
			}
			$url      = (string) ( $page['url'] ?? '' );
			$absolute = UrlKey::absolute( $url, $url );
			$identity = UrlKey::identity( $absolute );
			if ( '' !== $identity && UrlKey::sameSite( $identity, $this->home ) ) {
				if ( isset( $this->byIdentity[ $identity ] ) ) {
					++$this->collisions;
					$identity = '';
				} else {
					$this->byIdentity[ $identity ] = $id;
				}
			}
			$this->nodes[ $id ] = array(
				'id'       => $id,
				'post_id'  => (int) ( $page['post_id'] ?? 0 ),
				'title'    => (string) ( $page['title'] ?? '' ),
				'url'      => '' !== $absolute ? $absolute : $url,
				'identity' => $identity,
				'analysed' => (bool) ( $page['analysed'] ?? false ),
				'links'    => is_array( $page['links'] ?? null ) ? $page['links'] : array(),
			);
		}
	}

	private function chooseFront( string $requested ): void {
		if ( '' !== $requested && isset( $this->nodes[ $requested ] ) ) {
			$this->frontId = $requested;
			return;
		}
		$home = UrlKey::identity( UrlKey::absolute( $this->home, $this->home ) );
		if ( '' !== $home && isset( $this->byIdentity[ $home ] ) ) {
			$this->frontId = $this->byIdentity[ $home ];
		}
	}

	/**
	 * Record internal targets and unresolved addresses from one source.
	 *
	 * @param array<int, mixed> $links
	 */
	private function absorb( string $sourceId, string $sourceTitle, string $sourceUrl, array $links, string $forcedKind ): void {
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			$href     = (string) ( $link['href'] ?? '' );
			$absolute = UrlKey::absolute( $href, '' !== $sourceUrl ? $sourceUrl : $this->home );
			if ( '' === $absolute || UrlKey::systemPath( $absolute ) ) {
				continue;
			}
			$identity = UrlKey::identity( $absolute );
			if ( ! UrlKey::sameSite( $identity, $this->home ) ) {
				continue;
			}
			$kind   = 'template' === $forcedKind || 'template' === (string) ( $link['kind'] ?? '' ) ? 'template' : 'contextual';
			$target = $this->byIdentity[ $identity ] ?? '';
			if ( '' === $target || $target === $sourceId ) {
				if ( '' === $target ) {
					$this->rememberUnresolved( $sourceId, $sourceTitle, $absolute, $kind, (string) ( $link['anchor'] ?? '' ) );
				}
				continue;
			}
			$this->incoming[ $target ][ $kind ][ $sourceId ] = ( $this->incoming[ $target ][ $kind ][ $sourceId ] ?? 0 ) + 1;
			$this->outgoing[ $sourceId ][ $target ]          = true;
			if ( 'contextual' === $kind && isset( $this->nodes[ $sourceId ] ) ) {
				$this->nodes[ $sourceId ]['contextual_targets'][ $target ] = true;
				$this->nodes[ $sourceId ]['contextual_out']                = count( $this->nodes[ $sourceId ]['contextual_targets'] );
			}
		}
	}

	private function rememberUnresolved( string $sourceId, string $sourceTitle, string $absolute, string $kind, string $anchor ): void {
		$key = $sourceId . "\n" . UrlKey::identity( $absolute ) . "\n" . $kind;
		if ( ! isset( $this->unresolved[ $key ] ) ) {
			$this->unresolved[ $key ] = array(
				'from_id'     => $sourceId,
				'from_title'  => $sourceTitle,
				'href'        => $absolute,
				'kind'        => $kind,
				'anchor'      => $anchor,
				'occurrences' => 0,
			);
		}
		++$this->unresolved[ $key ]['occurrences'];
	}

	/**
	 * Count unique referring pages and repeated occurrences.
	 *
	 * @return array<string, array{referring_pages: int, contextual_referrers: int, template_referrers: int, contextual_occurrences: int, template_occurrences: int}>
	 */
	private function stats(): array {
		$stats = array();
		foreach ( array_keys( $this->nodes ) as $id ) {
			$contextual   = $this->incoming[ $id ]['contextual'] ?? array();
			$template     = $this->incoming[ $id ]['template'] ?? array();
			$sources      = array_unique( array_merge( array_keys( $contextual ), array_keys( $template ) ) );
			$stats[ $id ] = array(
				'referring_pages'        => count( $sources ),
				'contextual_referrers'   => count( $contextual ),
				'template_referrers'     => count( $template ),
				'contextual_occurrences' => array_sum( $contextual ),
				'template_occurrences'   => array_sum( $template ),
			);
		}
		return $stats;
	}

	/**
	 * Walk outward from the front page and from navigation targets.
	 *
	 * @param list<array<string, mixed>> $navigation
	 * @return array<string, int>
	 */
	private function depths( array $navigation ): array {
		$depth = array();
		$queue = array();
		if ( '' !== $this->frontId ) {
			$depth[ $this->frontId ] = 0;
			$queue[]                 = $this->frontId;
		}
		if ( array() !== $navigation ) {
			foreach ( array_keys( $this->outgoing['site:navigation'] ?? array() ) as $target ) {
				if ( ! isset( $depth[ $target ] ) ) {
					$depth[ $target ] = 1;
					$queue[]          = $target;
				}
			}
		}
		$cursor = 0;
		while ( isset( $queue[ $cursor ] ) ) {
			$current = $queue[ $cursor ];
			++$cursor;
			$next_depth = $depth[ $current ] + 1;
			foreach ( array_keys( $this->outgoing[ $current ] ?? array() ) as $target ) {
				if ( isset( $depth[ $target ] ) ) {
					continue;
				}
				$depth[ $target ] = $next_depth;
				$queue[]          = $target;
			}
		}
		return $depth;
	}

	/**
	 * Attach status and depth to every inventory page.
	 *
	 * @param array<string, array{referring_pages: int, contextual_referrers: int, template_referrers: int, contextual_occurrences: int, template_occurrences: int}> $stats
	 * @param array<string, int>                                                                                                                                     $depth
	 * @return array<string, array<string, mixed>>
	 */
	private function rows( array $stats, array $depth ): array {
		$rows = array();
		foreach ( $this->nodes as $id => $node ) {
			$item      = $stats[ $id ];
			$referrers = $item['referring_pages'];
			if ( $id === $this->frontId ) {
				$status = 'front';
			} elseif ( ! $this->complete && 0 === $referrers ) {
				$status = 'unknown';
			} elseif ( $this->complete && 0 === $referrers ) {
				$status = 'orphan';
			} elseif ( 0 === $item['contextual_referrers'] ) {
				$status = 'template_only';
			} elseif ( 1 === $item['contextual_referrers'] ) {
				$status = 'weak';
			} else {
				$status = 'linked';
			}
			$rows[ $id ] = array(
				'id'                      => $id,
				'post_id'                 => (int) $node['post_id'],
				'title'                   => (string) $node['title'],
				'url'                     => (string) $node['url'],
				'analysed'                => (bool) $node['analysed'],
				'depth'                   => $depth[ $id ] ?? null,
				'referring_pages'         => $item['referring_pages'],
				'contextual_referrers'    => $item['contextual_referrers'],
				'template_referrers'      => $item['template_referrers'],
				'contextual_occurrences'  => $item['contextual_occurrences'],
				'template_occurrences'    => $item['template_occurrences'],
				'contextual_out'          => (int) ( $node['contextual_out'] ?? 0 ),
				'contextual_referrer_ids' => array_map( 'strval', array_keys( $this->incoming[ $id ]['contextual'] ?? array() ) ),
				'status'                  => $status,
			);
		}
		return $rows;
	}

	/**
	 * Public report. Orphan rows are omitted when the crawl is incomplete.
	 *
	 * @param array<string, array<string, mixed>> $rows
	 * @param array<string, int>                  $depth
	 * @return array<string, mixed>
	 */
	private function report( array $rows, array $depth, int $navigationLinks ): array {
		$orphans       = $this->select( $rows, 'orphan' );
		$weak          = $this->select( $rows, 'weak' );
		$template_only = $this->select( $rows, 'template_only' );
		$unknown       = $this->select( $rows, 'unknown' );
		$unresolved    = array_values( $this->unresolved );
		usort(
			$unresolved,
			static function ( array $left, array $right ): int {
				$by_count = (int) $right['occurrences'] <=> (int) $left['occurrences'];
				return 0 !== $by_count ? $by_count : strcmp( (string) $left['href'], (string) $right['href'] );
			}
		);
		$unreachable = 0;
		$deepest     = null;
		$histogram   = array();
		foreach ( $rows as $row ) {
			if ( null === $row['depth'] ) {
				++$unreachable;
				$bucket = 'unknown';
			} else {
				$bucket = (string) (int) $row['depth'];
				if ( null === $deepest || (int) $row['depth'] > (int) $deepest['depth'] ) {
					$deepest = $row;
				}
			}
			$histogram[ $bucket ] = ( $histogram[ $bucket ] ?? 0 ) + 1;
		}
		$analysed = 0;
		foreach ( $rows as $row ) {
			if ( ! empty( $row['analysed'] ) ) {
				++$analysed;
			}
		}

		return array(
			'coverage'      => array(
				'complete'  => $this->complete,
				'inventory' => count( $rows ),
				'analysed'  => $analysed,
				'missing'   => $this->missing,
				'withheld'  => $this->withheld,
			),
			'front'         => array(
				'id'    => $this->frontId,
				'title' => '' !== $this->frontId ? (string) ( $rows[ $this->frontId ]['title'] ?? '' ) : '',
				'url'   => UrlKey::absolute( $this->home, $this->home ),
				'kind'  => '' !== $this->frontId ? 'page' : 'blog',
			),
			'counts'        => array(
				'inventory'       => count( $rows ),
				'analysed'        => $analysed,
				'orphans'         => $this->complete ? count( $orphans ) : null,
				'weak'            => count( $weak ),
				'template_only'   => count( $template_only ),
				'unknown'         => count( $unknown ),
				'unresolved'      => count( $unresolved ),
				'unreachable'     => $unreachable,
				'navigation'      => $navigationLinks,
				'address_clashes' => $this->collisions,
			),
			'depth'         => $histogram,
			'deepest'       => null === $deepest ? null : $this->brief( $deepest ),
			'orphans'       => $this->complete ? $this->limit( $orphans ) : array(),
			'weak'          => $this->limit( $weak ),
			'template_only' => $this->limit( $template_only ),
			'unresolved'    => $this->limit( $unresolved ),
			'limited'       => count( $orphans ) > self::LIST_LIMIT || count( $weak ) > self::LIST_LIMIT || count( $template_only ) > self::LIST_LIMIT || count( $unresolved ) > self::LIST_LIMIT,
			'notes'         => $this->notes(),
		);
	}

	/**
	 * Pages with one status, ordered by title.
	 *
	 * @param array<string, array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private function select( array $rows, string $status ): array {
		$chosen = array();
		foreach ( $rows as $row ) {
			if ( $status === (string) $row['status'] ) {
				$chosen[] = $this->brief( $row );
			}
		}
		usort(
			$chosen,
			static function ( array $left, array $right ): int {
				return strcasecmp( (string) $left['title'], (string) $right['title'] );
			}
		);
		return $chosen;
	}

	/**
	 * Fields the dashboard lists for one page.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function brief( array $row ): array {
		return array(
			'id'                     => (string) $row['id'],
			'post_id'                => (int) $row['post_id'],
			'title'                  => (string) $row['title'],
			'url'                    => (string) $row['url'],
			'analysed'               => (bool) $row['analysed'],
			'depth'                  => $row['depth'],
			'referring_pages'        => (int) $row['referring_pages'],
			'contextual_referrers'   => (int) $row['contextual_referrers'],
			'template_referrers'     => (int) $row['template_referrers'],
			'contextual_occurrences' => (int) $row['contextual_occurrences'],
			'template_occurrences'   => (int) $row['template_occurrences'],
			'status'                 => (string) $row['status'],
		);
	}

	/**
	 * Keep the first page of a long list.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private function limit( array $rows ): array {
		return array_slice( $rows, 0, self::LIST_LIMIT );
	}

	/**
	 * Sentences that explain how the counts were produced.
	 *
	 * @return string[]
	 */
	private function notes(): array {
		$notes = array(
			__( 'A referring page is counted once even when it repeats the same link. The occurrence count includes those repeats.', 'seo-studio' ),
			__( 'Contextual links sit in the content area. Template links sit outside that area, and navigation menus are template links too.', 'seo-studio' ),
			__( 'Zoop matches unresolved addresses to the published inventory and does not request them.', 'seo-studio' ),
		);
		if ( $this->withheld ) {
			$notes[] = __( 'This report includes published pages this account can edit. Orphan pages stay hidden because the account cannot see every published page.', 'seo-studio' );
		}
		if ( $this->missing > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of published pages with no stored analysis. */
				__( 'Orphan pages stay hidden until every published page has a stored analysis. %d published pages in this inventory have no stored analysis.', 'seo-studio' ),
				$this->missing
			);
			$notes[] = __( 'Crawl depth uses the stored links and navigation menus. Pages that have not been analysed can still contain links.', 'seo-studio' );
		} elseif ( $this->withheld ) {
			$notes[] = __( 'Crawl depth uses the pages this account can edit.', 'seo-studio' );
		}
		if ( '' === $this->frontId ) {
			$notes[] = __( 'The front page is the blog index, so depth starts at the site address and at navigation targets.', 'seo-studio' );
		}
		if ( $this->collisions > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of published pages that share an address with an earlier page. */
				__( '%d published pages share an address with another page. Links resolve to the first page.', 'seo-studio' ),
				$this->collisions
			);
		}
		return $notes;
	}
}
