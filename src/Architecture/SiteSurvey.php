<?php
/**
 * Published-page inventory for the link graph.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Architecture;

use SEOStudio\Core\Capabilities;
use SEOStudio\Jobs\PostTypes;
use SEOStudio\Storage\PagesRepository;

/**
 * Reads published posts, stored analyses, and navigation menus. It does not fetch URLs.
 */
final class SiteSurvey {
	public function __construct( private readonly PagesRepository $pages = new PagesRepository() ) {}

	/**
	 * Architecture report for the current user.
	 *
	 * The graph is calculated for every published page. Editors and authors then
	 * receive only the rows they can act on, with inaccessible details removed.
	 *
	 * @return array<string, mixed>
	 */
	public function report(): array {
		$collected                 = $this->collect();
		$evaluated                 = ( new Graph() )->evaluate( $collected['pages'], $collected['navigation'], $collected['context'] );
		$report                    = $evaluated['report'];
		$report['notes']           = array_merge( $collected['notes'], $report['notes'] );
		$report                    = $this->describe( $report, $collected['pages'] );
		$report['trusted_aliases'] = SiteBoundary::aliases();
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
			return $this->redact( $report );
		}
		return $report;
	}

	/**
	 * Per-page stats plus the public report.
	 *
	 * @return array{report: array<string, mixed>, pages: array<string, array<string, mixed>>}
	 */
	public function evaluated(): array {
		$collected                    = $this->collect();
		$evaluated                    = ( new Graph() )->evaluate( $collected['pages'], $collected['navigation'], $collected['context'] );
		$evaluated['report']['notes'] = array_merge( $collected['notes'], $evaluated['report']['notes'] );
		$evaluated['report']          = $this->describe( $evaluated['report'], $collected['pages'] );
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
			$evaluated['report'] = $this->redact( $evaluated['report'] );
		}
		return $evaluated;
	}

	/**
	 * Published pages, navigation, and the graph context.
	 *
	 * @return array{pages: list<array<string, mixed>>, navigation: list<array<string, mixed>>, context: array<string, mixed>, notes: string[]}
	 */
	private function collect(): array {
		$stored          = $this->pages->storedLinks();
		$navigation_hash = LinkSource::navigationFingerprint();
		$ids             = get_posts(
			array(
				'post_type'              => PostTypes::public(),
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'suppress_filters'       => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		$ids             = is_array( $ids ) ? $ids : array();
		$pages           = array();
		$missing         = 0;
		$content         = 0;
		$fresh           = 0;
		$stale_links     = 0;

		foreach ( $ids as $raw_id ) {
			$post_id = (int) $raw_id;
			if ( $post_id <= 0 ) {
				continue;
			}
			$permalink  = get_permalink( $post_id );
			$row        = $stored[ $post_id ] ?? null;
			$model      = is_array( $row ) ? (array) ( $row['model'] ?? array() ) : array();
			$analysed   = is_array( $row ) && ! empty( $row['analysed'] );
			$link_fresh = is_array( $row ) && LinkSource::isFresh( $model, $post_id, $navigation_hash );
			if ( $link_fresh ) {
				++$fresh;
			} elseif ( $analysed && 'live' === (string) ( $row['render_mode'] ?? '' ) ) {
				++$stale_links;
			}
			if ( $analysed ) {
				if ( 'live' !== (string) ( $row['render_mode'] ?? '' ) ) {
					++$content;
				}
			} else {
				++$missing;
			}
			$title   = get_the_title( $post_id );
			$pages[] = array(
				'id'           => 'post:' . $post_id,
				'post_id'      => $post_id,
				'title'        => html_entity_decode( wp_strip_all_tags( is_string( $title ) ? $title : '' ), ENT_QUOTES, 'UTF-8' ),
				'url'          => is_string( $permalink ) ? $permalink : '',
				'in_inventory' => true,
				'analysed'     => $analysed,
				'link_fresh'   => $link_fresh,
				'links'        => $link_fresh && is_array( $row ) ? $row['links'] : array(),
			);
		}

		$navigation = ( new NavigationSurvey() )->collect();
		$front_id   = '';
		if ( 'page' === (string) get_option( 'show_on_front' ) ) {
			$front = (int) get_option( 'page_on_front' );
			if ( $front > 0 ) {
				$front_id = 'post:' . $front;
			}
		}
		$home     = home_url( '/' );
		$complete = count( $pages ) > 0 && count( $pages ) === $fresh && '' === $navigation['limitation'];
		$resolver = new DestinationResolver();
		$notes    = array();
		if ( $content > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of analyses that read post content rather than the published HTML. */
				__( '%d stored analyses read the post content only. Their links are not treated as published theme evidence.', 'seo-studio' ),
				$content
			);
		}
		if ( $stale_links > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of pages whose stored published links are missing or out of date. */
				__( '%d pages have a stored published model whose link evidence is missing or out of date. Those links are withheld until the page is checked again.', 'seo-studio' ),
				$stale_links
			);
		}
		if ( '' !== $navigation['limitation'] ) {
			$notes[] = $navigation['limitation'];
		}
		if ( 0 === $navigation['menus'] ) {
			$notes[] = __( 'No assigned navigation menu is stored. Template links then come only from fresh published pages.', 'seo-studio' );
		} else {
			$notes[] = sprintf(
				/* translators: 1: assigned menus, 2: saved menus that are not assigned. */
				__( 'Zoop read %1$d assigned navigation menus. %2$d saved menus are not assigned and are not counted unless the published HTML shows their links.', 'seo-studio' ),
				$navigation['menus'],
				$navigation['unassigned']
			);
		}
		$notes[] = __( 'Link evidence is the state at the last successful published-page check. Zoop does not continuously re-read the theme.', 'seo-studio' );
		$gap     = array();
		if ( $missing > 0 ) {
			$gap[] = sprintf(
				/* translators: %d: published pages with no stored analysis. */
				_n(
					'%d published page has no stored analysis.',
					'%d published pages have no stored analysis.',
					$missing,
					'seo-studio'
				),
				$missing
			);
		}
		if ( $content > 0 ) {
			$gap[] = sprintf(
				/* translators: %d: analyses that read post content rather than the published HTML. */
				_n(
					'%d stored analysis read the post content only.',
					'%d stored analyses read the post content only.',
					$content,
					'seo-studio'
				),
				$content
			);
		}
		if ( $stale_links > 0 ) {
			$gap[] = sprintf(
				/* translators: %d: pages whose stored published links are missing or out of date. */
				_n(
					'%d page has missing or out-of-date published-link evidence.',
					'%d pages have missing or out-of-date published-link evidence.',
					$stale_links,
					'seo-studio'
				),
				$stale_links
			);
		}
		if ( '' !== $navigation['limitation'] ) {
			$gap[] = $navigation['limitation'];
		}

		return array(
			'pages'      => $pages,
			'navigation' => $navigation['links'],
			'context'    => array(
				'home'              => $home,
				'front_id'          => $front_id,
				'coverage_complete' => $complete,
				'missing'           => $missing + $content + $stale_links,
				'withheld'          => false,
				'aliases'           => SiteBoundary::aliasHosts( $home ),
				'coverage_gap'      => implode( ' ', $gap ),
				'coverage_reasons'  => array(
					'unanalysed'         => $missing,
					'content_only'       => $content,
					'stale_links'        => $stale_links,
					'navigation_limited' => '' !== $navigation['limitation'],
					'navigation_note'    => $navigation['limitation'],
				),
				'classifier'        => static function ( string $absolute ) use ( $resolver, $home ): array {
					return $resolver->classify( $absolute, $home );
				},
			),
			'notes'      => $notes,
		);
	}

	/**
	 * Add coverage sentences and candidate sources. Candidates use stored topics only.
	 *
	 * @param array<string, mixed>        $report
	 * @param list<array<string, mixed>>  $pages
	 * @return array<string, mixed>
	 */
	private function describe( array $report, array $pages ): array {
		$eligible = count( $pages );
		$fresh    = 0;
		foreach ( $pages as $page ) {
			if ( ! empty( $page['link_fresh'] ) ) {
				++$fresh;
			}
		}
		$evidence = sprintf(
			/* translators: 1: pages with current published-link evidence, 2: eligible published pages. */
			__( '%1$d of %2$d eligible pages have current published-link evidence.', 'seo-studio' ),
			$fresh,
			$eligible
		);
		foreach ( array( 'orphans', 'weak', 'template_only' ) as $list ) {
			if ( ! isset( $report[ $list ] ) || ! is_array( $report[ $list ] ) ) {
				continue;
			}
			$next = array();
			foreach ( $report[ $list ] as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$row['evidence']   = $evidence;
				$row['candidates'] = 'orphan' === (string) ( $row['status'] ?? '' ) ? $this->candidates( (int) ( $row['post_id'] ?? 0 ), $pages ) : array();
				$row['action']     = $this->candidateAction( $row['candidates'] );
				$next[]            = $row;
			}
			$report[ $list ] = $next;
		}
		$report['coverage']['fresh_links'] = $fresh;
		$report['coverage']['eligible']    = $eligible;
		return $report;
	}

	/**
	 * One sentence naming the stored pages a viewer could link from.
	 *
	 * @param list<array<string, mixed>> $candidates
	 */
	private function candidateAction( array $candidates ): string {
		if ( array() === $candidates ) {
			return __( 'No stored topic or keyphrase matches this page, so there is no candidate source to suggest.', 'seo-studio' );
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
	 * Up to three other inventory pages that share a saved topic or keyphrase.
	 *
	 * @param list<array<string, mixed>> $pages
	 * @return list<array<string, mixed>>
	 */
	private function candidates( int $postId, array $pages ): array {
		if ( $postId < 1 || ! function_exists( 'get_post_meta' ) ) {
			return array();
		}
		$topic  = trim( (string) get_post_meta( $postId, '_seostudio_primary_topic', true ) );
		$phrase = trim( (string) get_post_meta( $postId, '_seostudio_focus_keyphrase', true ) );
		if ( '' === $topic && '' === $phrase ) {
			return array();
		}
		$matches = array();
		foreach ( $pages as $page ) {
			$other = (int) ( $page['post_id'] ?? 0 );
			if ( $other < 1 || $other === $postId ) {
				continue;
			}
			$other_topic  = trim( (string) get_post_meta( $other, '_seostudio_primary_topic', true ) );
			$other_phrase = trim( (string) get_post_meta( $other, '_seostudio_focus_keyphrase', true ) );
			$rank         = 0;
			$reason       = '';
			if ( '' !== $topic && $topic === $other_topic ) {
				$rank   = 2;
				$reason = __( 'Same stored topic.', 'seo-studio' );
			} elseif ( '' !== $phrase && $phrase === $other_phrase ) {
				$rank   = 1;
				$reason = __( 'Same stored keyphrase.', 'seo-studio' );
			}
			if ( 0 === $rank ) {
				continue;
			}
			$matches[] = array(
				'post_id' => $other,
				'title'   => (string) ( $page['title'] ?? '' ),
				'url'     => (string) ( $page['url'] ?? '' ),
				'rank'    => $rank,
				'reason'  => $reason,
			);
		}
		usort(
			$matches,
			static function ( array $left, array $right ): int {
				$by_rank = (int) $right['rank'] <=> (int) $left['rank'];
				return 0 !== $by_rank ? $by_rank : strcasecmp( (string) $left['title'], (string) $right['title'] );
			}
		);
		return array_slice( $matches, 0, 3 );
	}

	/**
	 * Remove every user-visible detail the current user cannot edit.
	 *
	 * @param array<string, mixed> $report
	 * @return array<string, mixed>
	 */
	private function redact( array $report ): array {
		foreach ( array( 'orphans', 'weak', 'template_only' ) as $list ) {
			$report[ $list ] = $this->redactPages( is_array( $report[ $list ] ?? null ) ? $report[ $list ] : array() );
		}
		$report['unresolved'] = $this->redactLinks( is_array( $report['unresolved'] ?? null ) ? $report['unresolved'] : array() );
		$report['unverified'] = $this->redactLinks( is_array( $report['unverified'] ?? null ) ? $report['unverified'] : array() );
		$report['deepest']    = $this->redactDeepest( $report['deepest'] ?? null );
		$report['front']      = $this->redactFront( is_array( $report['front'] ?? null ) ? $report['front'] : array() );
		unset( $report['possible_aliases'], $report['trusted_aliases'] );
		$report['notes'] = array(
			__( 'This list includes published pages you can edit. Details of other pages are omitted.', 'seo-studio' ),
			__( 'Link counts were calculated for the whole published site before this list was filtered.', 'seo-studio' ),
		);
		if ( isset( $report['counts'] ) && is_array( $report['counts'] ) ) {
			if ( null !== ( $report['counts']['orphans'] ?? null ) ) {
				$report['counts']['orphans'] = count( is_array( $report['orphans'] ?? null ) ? $report['orphans'] : array() );
			}
			foreach ( array( 'weak', 'template_only', 'unresolved', 'unverified' ) as $key ) {
				if ( isset( $report[ $key ] ) && is_array( $report[ $key ] ) ) {
					$report['counts'][ $key ] = count( $report[ $key ] );
				}
			}
		}
		return $report;
	}

	/**
	 * Keep architecture rows the current user can edit.
	 *
	 * @param array<int, mixed> $rows
	 * @return list<array<string, mixed>>
	 */
	private function redactPages( array $rows ): array {
		$visible = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$post_id = (int) ( $row['post_id'] ?? 0 );
			if ( $post_id < 1 || ! Capabilities::canEditPost( $post_id ) ) {
				continue;
			}
			$row['contextual_referrer_ids'] = array();
			$row['candidates']              = $this->redactCandidates( is_array( $row['candidates'] ?? null ) ? $row['candidates'] : array() );
			$row['action']                  = $this->candidateAction( $row['candidates'] );
			$visible[]                      = $row;
		}
		return $visible;
	}

	/**
	 * Keep candidate sources the current user can edit.
	 *
	 * @param array<int, mixed> $candidates
	 * @return list<array<string, mixed>>
	 */
	private function redactCandidates( array $candidates ): array {
		$visible = array();
		foreach ( $candidates as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$post_id = (int) ( $candidate['post_id'] ?? 0 );
			if ( $post_id > 0 && Capabilities::canEditPost( $post_id ) ) {
				$visible[] = $candidate;
			}
		}
		return $visible;
	}

	/**
	 * Hide the deepest page when the current user cannot edit it.
	 *
	 * @param mixed $deepest Deepest page brief.
	 * @return array<string, mixed>|null
	 */
	private function redactDeepest( mixed $deepest ): ?array {
		if ( ! is_array( $deepest ) ) {
			return null;
		}
		$post_id = (int) ( $deepest['post_id'] ?? 0 );
		if ( $post_id < 1 || ! Capabilities::canEditPost( $post_id ) ) {
			return null;
		}
		return $deepest;
	}

	/**
	 * Hide a front page the current user cannot edit.
	 *
	 * @param array<string, mixed> $front Front-page brief.
	 * @return array<string, mixed>
	 */
	private function redactFront( array $front ): array {
		$front_id = (string) ( $front['id'] ?? '' );
		$post_id  = 0;
		if ( 1 === preg_match( '/(\d+)$/', $front_id, $matches ) ) {
			$post_id = (int) $matches[1];
		}
		if ( $post_id > 0 && ! Capabilities::canEditPost( $post_id ) ) {
			$front['id']    = '';
			$front['title'] = '';
			$front['url']   = '';
		}
		return $front;
	}

	/**
	 * Keep unresolved addresses that start on a page the current user can edit.
	 *
	 * @param array<int, mixed> $links
	 * @return list<array<string, mixed>>
	 */
	private function redactLinks( array $links ): array {
		$visible = array();
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			$from    = (string) ( $link['from_id'] ?? '' );
			$post_id = 0;
			if ( 1 === preg_match( '/(\d+)$/', $from, $matches ) ) {
				$post_id = (int) $matches[1];
			}
			if ( $post_id < 1 || ! Capabilities::canEditPost( $post_id ) ) {
				continue;
			}
			$visible[] = $link;
		}
		return $visible;
	}
}
