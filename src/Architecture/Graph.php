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
	 * Menu identities waiting for a published observation.
	 *
	 * @var array<string, array{occurrences: int, anchor: string, href: string, verified: bool}>
	 */
	private array $pendingNavigation = array();

	/**
	 * Same-site addresses that could not be classified. They are not confirmed broken links.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $unknownDestinations = array();

	/**
	 * Trusted host aliases supplied with this survey.
	 *
	 * @var string[]
	 */
	private array $aliases = array();

	/**
	 * Local classifier for addresses that miss the inventory. Null keeps the legacy unresolved bucket.
	 *
	 * @var callable(string): array{status: string, reason: string}|null
	 */
	private $classifier = null;

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
	 * True when every published page in view has current published-link evidence.
	 *
	 * @var bool
	 */
	private bool $complete = false;

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
	 * Why published-link coverage is incomplete, when the survey knows.
	 *
	 * @var string
	 */
	private string $coverageGap = '';

	/**
	 * Separate reasons coverage is incomplete. Missing analysis is not the only one.
	 *
	 * @var array{unanalysed: int, content_only: int, stale_links: int, navigation_limited: bool, navigation_note: string}
	 */
	private array $reasons = array(
		'unanalysed'         => 0,
		'content_only'       => 0,
		'stale_links'        => 0,
		'navigation_limited' => false,
		'navigation_note'    => '',
	);

	/**
	 * Untrusted www or non-www hosts and how many links point at them.
	 *
	 * @var array<string, int>
	 */
	private array $possibleAliases = array();

	/**
	 * Inventory pages whose path is also linked from an untrusted twin host.
	 *
	 * @var array<string, string>
	 */
	private array $aliasUncertain = array();

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
		$this->home        = (string) ( $context['home'] ?? '' );
		$this->complete    = (bool) ( $context['coverage_complete'] ?? false );
		$this->withheld    = (bool) ( $context['withheld'] ?? false );
		$this->aliases     = is_array( $context['aliases'] ?? null ) ? array_map( 'strval', $context['aliases'] ) : array();
		$classifier        = $context['classifier'] ?? null;
		$this->classifier  = is_callable( $classifier ) ? $classifier : null;
		$this->coverageGap = (string) ( $context['coverage_gap'] ?? '' );
		$this->reasons     = $this->reasonsFrom( $context );
		$this->index( $pages );
		$this->chooseFront( (string) ( $context['front_id'] ?? '' ) );

		$this->absorb( 'site:navigation', __( 'Site navigation', 'seo-studio' ), $this->home, $navigation, 'navigation' );
		foreach ( $this->nodes as $id => $node ) {
			if ( empty( $node['analysed'] ) || ( array_key_exists( 'link_fresh', $node ) && empty( $node['link_fresh'] ) ) ) {
				continue;
			}
			$this->absorb( (string) $id, (string) $node['title'], (string) $node['url'], (array) $node['links'], '' );
		}
		$this->commitNavigation();

		$stats = $this->stats();
		$depth = $this->depths( $navigation );
		$rows  = $this->rows( $stats, $depth );

		return array(
			'report' => $this->report( $rows, $depth, count( $navigation ) ),
			'pages'  => $rows,
		);
	}

	private function reset(): void {
		$this->nodes               = array();
		$this->byIdentity          = array();
		$this->incoming            = array();
		$this->outgoing            = array();
		$this->unresolved          = array();
		$this->unknownDestinations = array();
		$this->pendingNavigation   = array();
		$this->aliases             = array();
		$this->classifier          = null;
		$this->coverageGap         = '';
		$this->possibleAliases     = array();
		$this->aliasUncertain      = array();
		$this->frontId             = '';
		$this->collisions          = 0;
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
			$identity = UrlKey::primaryIdentity( UrlKey::identity( $absolute ), $this->home, $this->aliases );
			if ( '' !== $identity ) {
				if ( isset( $this->byIdentity[ $identity ] ) ) {
					++$this->collisions;
					$identity = '';
				} else {
					$this->byIdentity[ $identity ] = $id;
				}
			}
			$this->nodes[ $id ] = array(
				'id'         => $id,
				'post_id'    => (int) ( $page['post_id'] ?? 0 ),
				'title'      => (string) ( $page['title'] ?? '' ),
				'url'        => '' !== $absolute ? $absolute : $url,
				'identity'   => $identity,
				'analysed'   => (bool) ( $page['analysed'] ?? false ),
				'link_fresh' => array_key_exists( 'link_fresh', $page ) ? (bool) $page['link_fresh'] : true,
				'links'      => is_array( $page['links'] ?? null ) ? $page['links'] : array(),
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
			if ( ! UrlKey::sameSite( $identity, $this->home, $this->aliases ) ) {
				$this->rememberPossibleAlias( $identity );
				continue;
			}
			$identity = UrlKey::primaryIdentity( $identity, $this->home, $this->aliases );
			if ( '' === $identity ) {
				continue;
			}
			$link_kind = (string) ( $link['kind'] ?? '' );
			if ( 'navigation' === $forcedKind || 'navigation' === $link_kind ) {
				$kind = 'navigation';
			} elseif ( 'template' === $forcedKind || 'template' === $link_kind ) {
				$kind = 'template';
			} else {
				$kind = 'contextual';
			}
			$verified = array_key_exists( 'verified', $link ) ? (bool) $link['verified'] : true;
			if ( 'navigation' === $kind && 'site:navigation' === $sourceId && ! $verified ) {
				if ( ! isset( $this->pendingNavigation[ $identity ] ) ) {
					$this->pendingNavigation[ $identity ] = array(
						'occurrences' => 0,
						'anchor'      => (string) ( $link['anchor'] ?? '' ),
						'href'        => $absolute,
						'verified'    => false,
					);
				}
				++$this->pendingNavigation[ $identity ]['occurrences'];
				continue;
			}
			if ( 'template' === $kind && 'site:navigation' !== $sourceId && isset( $this->pendingNavigation[ $identity ] ) ) {
				$this->pendingNavigation[ $identity ]['verified'] = true;
				continue;
			}
			$target = $this->byIdentity[ $identity ] ?? '';
			if ( '' === $target || $target === $sourceId ) {
				if ( '' === $target ) {
					$this->rememberUnresolved( $sourceId, $sourceTitle, $absolute, $kind, (string) ( $link['anchor'] ?? '' ) );
				}
				continue;
			}
			$count_kind = 'navigation' === $kind ? 'template' : $kind;
			$this->incoming[ $target ][ $count_kind ][ $sourceId ] = ( $this->incoming[ $target ][ $count_kind ][ $sourceId ] ?? 0 ) + 1;
			if ( 'navigation' === $kind ) {
				$this->incoming[ $target ]['navigation'][ $sourceId ] = ( $this->incoming[ $target ]['navigation'][ $sourceId ] ?? 0 ) + 1;
			}
			$this->outgoing[ $sourceId ][ $target ] = true;
			if ( 'contextual' === $kind && isset( $this->nodes[ $sourceId ] ) ) {
				$this->nodes[ $sourceId ]['contextual_targets'][ $target ] = true;
				$this->nodes[ $sourceId ]['contextual_out']                = count( $this->nodes[ $sourceId ]['contextual_targets'] );
			}
		}
	}

	/**
	 * An untrusted www twin is not an incoming link. A matching path withholds an orphan claim.
	 */
	private function rememberPossibleAlias( string $identity ): void {
		$twin = UrlKey::wwwTwinHost( $identity, $this->home );
		if ( '' === $twin ) {
			return;
		}
		$this->possibleAliases[ $twin ] = ( $this->possibleAliases[ $twin ] ?? 0 ) + 1;
		$primary                        = UrlKey::primaryIdentity( $identity, $this->home, array( $twin ) );
		$target                         = '' !== $primary ? ( $this->byIdentity[ $primary ] ?? '' ) : '';
		if ( '' !== $target ) {
			$this->aliasUncertain[ $target ] = $twin;
		}
	}

	private function commitNavigation(): void {
		foreach ( $this->pendingNavigation as $identity => $row ) {
			if ( empty( $row['verified'] ) ) {
				continue;
			}
			$target = $this->byIdentity[ $identity ] ?? '';
			if ( '' === $target ) {
				$this->rememberUnresolved( 'site:navigation', __( 'Site navigation', 'seo-studio' ), (string) $row['href'], 'navigation', (string) $row['anchor'] );
				continue;
			}
			$occurrences = max( 1, (int) $row['occurrences'] );
			$this->incoming[ $target ]['template']['site:navigation']   = ( $this->incoming[ $target ]['template']['site:navigation'] ?? 0 ) + $occurrences;
			$this->incoming[ $target ]['navigation']['site:navigation'] = ( $this->incoming[ $target ]['navigation']['site:navigation'] ?? 0 ) + $occurrences;
			$this->outgoing['site:navigation'][ $target ]               = true;
		}
	}

	private function rememberUnresolved( string $sourceId, string $sourceTitle, string $absolute, string $kind, string $anchor ): void {
		$resolution = 'unresolved';
		$reason     = '';
		if ( null !== $this->classifier ) {
			$resolved   = ( $this->classifier )( $absolute );
			$resolution = (string) ( $resolved['status'] ?? 'unknown' );
			$reason     = (string) ( $resolved['reason'] ?? '' );
			if ( in_array( $resolution, array( 'published', 'archive', 'media', 'query', 'system', 'redirect', 'external', 'non_public' ), true ) ) {
				return;
			}
		}
		$key = $sourceId . "\n" . UrlKey::identity( $absolute ) . "\n" . $kind;
		if ( 'unknown' === $resolution ) {
			if ( ! isset( $this->unknownDestinations[ $key ] ) ) {
				$this->unknownDestinations[ $key ] = array(
					'from_id'     => $sourceId,
					'from_title'  => $sourceTitle,
					'href'        => $absolute,
					'kind'        => $kind,
					'anchor'      => $anchor,
					'occurrences' => 0,
					'resolution'  => 'unknown',
					'reason'      => $reason,
				);
			}
			++$this->unknownDestinations[ $key ]['occurrences'];
			return;
		}
		if ( ! isset( $this->unresolved[ $key ] ) ) {
			$this->unresolved[ $key ] = array(
				'from_id'     => $sourceId,
				'from_title'  => $sourceTitle,
				'href'        => $absolute,
				'kind'        => $kind,
				'anchor'      => $anchor,
				'occurrences' => 0,
				'resolution'  => 'missing' === $resolution ? 'missing' : 'unresolved',
				'reason'      => $reason,
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
			} elseif ( 0 === $referrers && isset( $this->aliasUncertain[ $id ] ) ) {
				$status = 'unknown';
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
			$row = array(
				'id'                      => $id,
				'post_id'                 => (int) $node['post_id'],
				'title'                   => (string) $node['title'],
				'url'                     => (string) $node['url'],
				'analysed'                => (bool) $node['analysed'],
				'link_fresh'              => (bool) $node['link_fresh'],
				'depth'                   => $depth[ $id ] ?? null,
				'referring_pages'         => $item['referring_pages'],
				'contextual_referrers'    => $item['contextual_referrers'],
				'template_referrers'      => $item['template_referrers'],
				'navigation_referrers'    => count( $this->incoming[ $id ]['navigation'] ?? array() ),
				'contextual_occurrences'  => $item['contextual_occurrences'],
				'template_occurrences'    => $item['template_occurrences'],
				'contextual_out'          => (int) ( $node['contextual_out'] ?? 0 ),
				'contextual_referrer_ids' => array_map( 'strval', array_keys( $this->incoming[ $id ]['contextual'] ?? array() ) ),
				'status'                  => $status,
			);
			if ( 'orphan' === $status ) {
				$row['observation'] = __( 'No verified incoming links were found.', 'seo-studio' );
				$row['confidence']  = 'high';
				$row['verify']      = __( 'Check the published pages again after updating links.', 'seo-studio' );
			} elseif ( 'unknown' === $status ) {
				if ( isset( $this->aliasUncertain[ $id ] ) ) {
					$evidence = sprintf(
						/* translators: %s: hostname that may belong to this site. */
						__( 'A link uses the same path on %s, and that hostname is not trusted as this site. This page is not called an orphan.', 'seo-studio' ),
						$this->aliasUncertain[ $id ]
					);
				} else {
					$evidence = __( 'Published-link coverage is incomplete, so this page is not called an orphan.', 'seo-studio' );
				}
				if ( '' !== $this->coverageGap ) {
					$evidence .= ' ' . $this->coverageGap;
				}
				$row['observation'] = __( 'Not yet verified.', 'seo-studio' );
				$row['evidence']    = $evidence;
				$row['confidence']  = 'limited';
				$row['verify']      = __( 'Check the published page.', 'seo-studio' );
			} elseif ( 'template_only' === $status ) {
				$row['observation'] = __( 'Verified template or navigation links were found, and no contextual links.', 'seo-studio' );
				$row['confidence']  = $this->complete ? 'high' : 'limited';
				$row['verify']      = __( 'Check the published page if the template changes.', 'seo-studio' );
			}
			$rows[ $id ] = $row;
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

		$unreachable_count = $this->complete ? $unreachable : null;
		$needs_check       = $this->reasons['unanalysed'] + $this->reasons['content_only'] + $this->reasons['stale_links'];

		return array(
			'coverage'           => array(
				'complete'              => $this->complete,
				'inventory'             => count( $rows ),
				'analysed'              => $analysed,
				'missing'               => $this->reasons['unanalysed'],
				'needs_published_check' => $needs_check,
				'withheld'              => $this->withheld,
				'reasons'               => $this->reasons,
			),
			'front'              => array(
				'id'    => $this->frontId,
				'title' => '' !== $this->frontId ? (string) ( $rows[ $this->frontId ]['title'] ?? '' ) : '',
				'url'   => UrlKey::absolute( $this->home, $this->home ),
				'kind'  => '' !== $this->frontId ? 'page' : 'blog',
			),
			'counts'             => array(
				'inventory'             => count( $rows ),
				'analysed'              => $analysed,
				'orphans'               => $this->complete ? count( $orphans ) : null,
				'weak'                  => count( $weak ),
				'template_only'         => count( $template_only ),
				'unknown'               => count( $unknown ),
				'unresolved'            => count( $unresolved ),
				'unverified'            => count( $this->unknownDestinations ),
				'unreachable'           => $unreachable_count,
				'needs_published_check' => $needs_check,
				'navigation'            => $navigationLinks,
				'address_clashes'       => $this->collisions,
			),
			'depth'              => $histogram,
			'deepest'            => null === $deepest ? null : $this->brief( $deepest ),
			'orphans'            => $this->complete ? $this->limit( $orphans ) : array(),
			'weak'               => $this->limit( $weak ),
			'template_only'      => $this->limit( $template_only ),
			'unresolved'         => $this->limit( $unresolved ),
			'unverified'         => $this->limit( array_values( $this->unknownDestinations ) ),
			'possible_aliases'   => $this->aliasNotices(),
			'limited'            => count( $orphans ) > self::LIST_LIMIT || count( $weak ) > self::LIST_LIMIT || count( $template_only ) > self::LIST_LIMIT || count( $unresolved ) > self::LIST_LIMIT || count( $this->unknownDestinations ) > self::LIST_LIMIT,
			'notes'              => $this->notes(),
			'depth_explanation'  => $this->depthExplanation(),
			'orphan_explanation' => $this->orphanExplanation(),
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
			'navigation_referrers'   => (int) ( $row['navigation_referrers'] ?? 0 ),
			'status'                 => (string) $row['status'],
			'observation'            => (string) ( $row['observation'] ?? '' ),
			'evidence'               => (string) ( $row['evidence'] ?? '' ),
			'confidence'             => (string) ( $row['confidence'] ?? '' ),
			'verify'                 => (string) ( $row['verify'] ?? '' ),
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
	 * One notice per untrusted www or non-www hostname.
	 *
	 * @return list<array{host: string, count: int, pages: int, message: string}>
	 */
	private function aliasNotices(): array {
		$notices = array();
		foreach ( $this->possibleAliases as $host => $count ) {
			$host  = (string) $host;
			$count = (int) $count;
			$pages = 0;
			foreach ( $this->aliasUncertain as $uncertain ) {
				if ( $uncertain === $host ) {
					++$pages;
				}
			}
			$message = sprintf(
				/* translators: 1: number of links, 2: hostname. */
				_n(
					'%1$d link points to %2$s, which may be this site.',
					'%1$d links point to %2$s, which may be this site.',
					$count,
					'seo-studio'
				),
				$count,
				$host
			);
			if ( $pages > 0 ) {
				$message .= ' ' . sprintf(
					/* translators: %d: number of pages that are not called orphans. */
					_n(
						'%d page is not called an orphan until that hostname is trusted.',
						'%d pages are not called orphans until that hostname is trusted.',
						$pages,
						'seo-studio'
					),
					$pages
				);
			}
			$notices[] = array(
				'host'    => $host,
				'count'   => $count,
				'pages'   => $pages,
				'message' => $message,
			);
		}
		return $notices;
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
		if ( ! $this->complete ) {
			$notes[] = __( 'Orphans are not counted yet.', 'seo-studio' );
			foreach ( $this->causeSentences() as $cause ) {
				$notes[] = $cause;
			}
			if ( $this->reasons['unanalysed'] > 0 ) {
				$notes[] = __( 'Run a site audit to analyse page content. A content-only site audit does not restore published-link evidence.', 'seo-studio' );
			}
			if ( $this->reasons['content_only'] > 0 || $this->reasons['stale_links'] > 0 ) {
				$notes[] = __( 'Check the published pages to record current link evidence. A content-only site audit does not restore published links.', 'seo-studio' );
			}
			$needs_check = $this->reasons['unanalysed'] + $this->reasons['content_only'] + $this->reasons['stale_links'];
			if ( $needs_check > 0 ) {
				$notes[] = sprintf(
					/* translators: %d: published pages that still need a published-page check. */
					_n(
						'%d page needs a published-page check before orphans can be counted.',
						'%d pages need a published-page check before orphans can be counted.',
						$needs_check,
						'seo-studio'
					),
					$needs_check
				);
			}
			$notes[] = __( 'Depth is not known yet.', 'seo-studio' );
		} else {
			$notes[] = __( 'Pages with no verified path have none from the modelled entry points. That is not proof that visitors or search engines cannot reach them.', 'seo-studio' );
		}
		if ( $this->withheld && $this->complete ) {
			$notes[] = __( 'Crawl depth uses the pages this account can edit.', 'seo-studio' );
		}
		if ( '' === $this->frontId ) {
			$notes[] = __( 'The front page is the blog index, so depth starts at the site address and at navigation targets.', 'seo-studio' );
		}
		foreach ( $this->aliasNotices() as $notice ) {
			$notes[] = (string) $notice['message'];
		}
		if ( array() !== $this->unknownDestinations ) {
			$notes[] = __( 'Some same-site addresses did not match a local WordPress route. They are unverified, not confirmed broken links.', 'seo-studio' );
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

	/**
	 * Coverage reasons from the survey, or the legacy missing count as unanalysed pages.
	 *
	 * @param array<string, mixed> $context Graph context.
	 * @return array{unanalysed: int, content_only: int, stale_links: int, navigation_limited: bool, navigation_note: string}
	 */
	private function reasonsFrom( array $context ): array {
		$given = $context['coverage_reasons'] ?? null;
		if ( ! is_array( $given ) ) {
			return array(
				'unanalysed'         => max( 0, (int) ( $context['missing'] ?? 0 ) ),
				'content_only'       => 0,
				'stale_links'        => 0,
				'navigation_limited' => false,
				'navigation_note'    => '',
			);
		}

		return array(
			'unanalysed'         => max( 0, (int) ( $given['unanalysed'] ?? 0 ) ),
			'content_only'       => max( 0, (int) ( $given['content_only'] ?? 0 ) ),
			'stale_links'        => max( 0, (int) ( $given['stale_links'] ?? 0 ) ),
			'navigation_limited' => ! empty( $given['navigation_limited'] ),
			'navigation_note'    => (string) ( $given['navigation_note'] ?? '' ),
		);
	}

	/**
	 * One sentence per evidence gap. Unanalysed pages are named only when they exist.
	 *
	 * @return string[]
	 */
	private function causeSentences(): array {
		$sentences  = array();
		$unanalysed = $this->reasons['unanalysed'];
		$content    = $this->reasons['content_only'];
		$stale      = $this->reasons['stale_links'];
		if ( $unanalysed > 0 ) {
			$sentences[] = sprintf(
				/* translators: %d: published pages with no stored analysis. */
				_n(
					'%d published page has not been analysed.',
					'%d published pages have not been analysed.',
					$unanalysed,
					'seo-studio'
				),
				$unanalysed
			);
		}
		if ( $content > 0 ) {
			$sentences[] = sprintf(
				/* translators: %d: analyses that read post content rather than the published HTML. */
				_n(
					'%d page was analysed only from content, without published-link verification.',
					'%d pages were analysed only from content, without published-link verification.',
					$content,
					'seo-studio'
				),
				$content
			);
		}
		if ( $stale > 0 ) {
			$sentences[] = sprintf(
				/* translators: %d: pages whose stored published links are missing or out of date. */
				_n(
					'%d page has a stale published-link observation.',
					'%d pages have stale published-link observations.',
					$stale,
					'seo-studio'
				),
				$stale
			);
		}
		if ( $this->reasons['navigation_limited'] ) {
			$note        = $this->reasons['navigation_note'];
			$sentences[] = '' !== $note ? $note : __( 'Navigation coverage is incomplete.', 'seo-studio' );
		}

		return $sentences;
	}

	private function depthExplanation(): string {
		if ( $this->complete ) {
			return __( 'These pages have no verified path from the modelled entry points. That is not proof that visitors or search engines cannot reach them.', 'seo-studio' );
		}

		$causes = $this->causeSentences();
		$lead   = __( 'Depth is not known yet.', 'seo-studio' );
		if ( array() === $causes ) {
			return $lead . ' ' . __( 'Published-link coverage is incomplete.', 'seo-studio' );
		}

		return $lead . ' ' . implode( ' ', $causes );
	}

	private function orphanExplanation(): string {
		if ( $this->complete ) {
			return __( 'An orphan has no verified incoming link. Template-only pages are listed separately.', 'seo-studio' );
		}

		$causes = $this->causeSentences();
		$lead   = __( 'Orphans are not counted yet.', 'seo-studio' );
		if ( array() === $causes ) {
			return $lead . ' ' . __( 'Published-link coverage is incomplete.', 'seo-studio' );
		}

		return $lead . ' ' . implode( ' ', $causes );
	}
}
