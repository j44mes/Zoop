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
	 * Public architecture report.
	 *
	 * @return array<string, mixed>
	 */
	public function report(): array {
		$collected       = $this->collect();
		$evaluated       = ( new Graph() )->evaluate( $collected['pages'], $collected['navigation'], $collected['context'] );
		$report          = $evaluated['report'];
		$report['notes'] = array_merge( $collected['notes'], $report['notes'] );
		return $report;
	}

	/**
	 * Per-page stats plus the public report. Later reports read this instead of recomputing rules.
	 *
	 * @return array{report: array<string, mixed>, pages: array<string, array<string, mixed>>}
	 */
	public function evaluated(): array {
		$collected                    = $this->collect();
		$evaluated                    = ( new Graph() )->evaluate( $collected['pages'], $collected['navigation'], $collected['context'] );
		$evaluated['report']['notes'] = array_merge( $collected['notes'], $evaluated['report']['notes'] );
		return $evaluated;
	}

	/**
	 * Gather inventory pages, menu links, and the coverage flags.
	 *
	 * @return array{pages: list<array<string, mixed>>, navigation: list<array<string, mixed>>, context: array<string, mixed>, notes: string[]}
	 */
	private function collect(): array {
		$stored  = $this->pages->storedLinks();
		$see_all = current_user_can( 'manage_options' );
		$ids     = get_posts(
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
		$ids     = is_array( $ids ) ? $ids : array();
		$pages   = array();
		$missing = 0;
		$content = 0;

		foreach ( $ids as $raw_id ) {
			$post_id = (int) $raw_id;
			if ( $post_id <= 0 ) {
				continue;
			}
			if ( ! $see_all && ! Capabilities::canEditPost( $post_id ) ) {
				continue;
			}
			$permalink = get_permalink( $post_id );
			$row       = $stored[ $post_id ] ?? null;
			$analysed  = is_array( $row ) && ! empty( $row['analysed'] );
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
				'links'        => $analysed && is_array( $row ) ? $row['links'] : array(),
			);
		}

		$navigation = $this->navigation();
		$front_id   = '';
		if ( 'page' === (string) get_option( 'show_on_front' ) ) {
			$front = (int) get_option( 'page_on_front' );
			if ( $front > 0 ) {
				$front_id = 'post:' . $front;
			}
		}

		$notes = array();
		if ( $content > 0 ) {
			$notes[] = sprintf(
				/* translators: %d: number of analyses that read post content rather than the published HTML. */
				__( '%d stored analyses read the post content only. Header and footer links on those pages are not in the graph. Menu links are still included.', 'seo-studio' ),
				$content
			);
		}
		if ( 0 === $navigation['menus'] ) {
			$notes[] = __( 'No navigation menu is stored. Template links then come only from analysed pages.', 'seo-studio' );
		} else {
			$notes[] = sprintf(
				/* translators: %d: number of stored navigation menus. */
				__( 'Zoop read %d navigation menus. A menu counts even when it is not assigned to a theme location.', 'seo-studio' ),
				$navigation['menus']
			);
		}
		return array(
			'pages'      => $pages,
			'navigation' => $navigation['links'],
			'context'    => array(
				'home'              => home_url( '/' ),
				'front_id'          => $front_id,
				'coverage_complete' => $see_all && 0 === $missing,
				'missing'           => $missing,
				'withheld'          => ! $see_all,
			),
			'notes'      => $notes,
		);
	}

	/**
	 * Links from every stored navigation menu.
	 *
	 * @return array{menus: int, links: list<array{href: string, anchor: string, kind: string}>}
	 */
	private function navigation(): array {
		$links = array();
		$menus = 0;
		$terms = function_exists( 'wp_get_nav_menus' ) ? wp_get_nav_menus() : array();
		if ( ! is_array( $terms ) ) {
			return array(
				'menus' => 0,
				'links' => array(),
			);
		}

		foreach ( $terms as $menu ) {
			++$menus;
			$term_id = is_object( $menu ) ? (int) $menu->term_id : 0;
			$items   = $term_id > 0 ? wp_get_nav_menu_items( $term_id ) : array();
			if ( ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				if ( ! is_object( $item ) ) {
					continue;
				}
				$href = (string) ( $item->url ?? '' );
				if ( '' === $href || '#' === $href ) {
					continue;
				}
				$links[] = array(
					'href'   => $href,
					'anchor' => (string) ( $item->title ?? '' ),
					'kind'   => 'template',
				);
			}
		}

		return array(
			'menus' => $menus,
			'links' => $links,
		);
	}
}
