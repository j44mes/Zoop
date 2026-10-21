<?php
/**
 * Navigation that is actually part of the public site.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Architecture;

/**
 * Assigned classic menus and block navigation referenced by the active templates.
 */
final class NavigationSurvey {
	/**
	 * Assigned classic menus and referenced block navigation. Unassigned menus stay out.
	 *
	 * @return array{links: list<array<string, mixed>>, menus: int, unassigned: int, limitation: string}
	 */
	public function collect(): array {
		$links      = array();
		$menus      = 0;
		$unassigned = 0;
		$locations  = function_exists( 'get_nav_menu_locations' ) ? get_nav_menu_locations() : array();
		$locations  = is_array( $locations ) ? $locations : array();
		$assigned   = array();
		foreach ( $locations as $menu_id ) {
			$menu_id = (int) $menu_id;
			if ( $menu_id > 0 ) {
				$assigned[ $menu_id ] = true;
			}
		}
		if ( function_exists( 'wp_get_nav_menus' ) ) {
			$terms = wp_get_nav_menus();
			if ( is_array( $terms ) ) {
				foreach ( $terms as $menu ) {
					$term_id = is_object( $menu ) ? (int) $menu->term_id : 0;
					if ( $term_id < 1 ) {
						continue;
					}
					if ( ! isset( $assigned[ $term_id ] ) ) {
						++$unassigned;
						continue;
					}
					++$menus;
					$links = array_merge( $links, $this->menuLinks( $term_id ) );
				}
			}
		}

		$blocks = LinkSource::referencedBlockNavigation();
		foreach ( $blocks['ids'] as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			++$menus;
			$links = array_merge( $links, $this->blockLinks( (string) $post->post_content ) );
		}
		foreach ( $blocks['inline'] ?? array() as $inline ) {
			if ( ! is_array( $inline ) ) {
				continue;
			}
			$href = (string) ( $inline['href'] ?? '' );
			if ( '' === $href ) {
				continue;
			}
			$links[] = array(
				'href'     => $href,
				'anchor'   => (string) ( $inline['anchor'] ?? '' ),
				'kind'     => 'navigation',
				'verified' => false,
			);
		}

		return array(
			'links'      => $links,
			'menus'      => $menus,
			'unassigned' => $unassigned,
			'limitation' => LinkSource::navigationLimitation(),
		);
	}

	/**
	 * Links from one assigned classic menu. They are unverified until published HTML shows them.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function menuLinks( int $termId ): array {
		$items = function_exists( 'wp_get_nav_menu_items' ) ? wp_get_nav_menu_items( $termId ) : array();
		if ( ! is_array( $items ) ) {
			return array();
		}
		$links = array();
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}
			$href = (string) ( $item->url ?? '' );
			if ( '' === $href || '#' === $href ) {
				continue;
			}
			$links[] = array(
				'href'     => $href,
				'anchor'   => (string) ( $item->title ?? '' ),
				'kind'     => 'navigation',
				'verified' => false,
			);
		}
		return $links;
	}

	/**
	 * Links stored inside one navigation post.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function blockLinks( string $content ): array {
		$links = array();
		if ( preg_match_all( '/wp:navigation-link\s+(\{.*?\})/s', $content, $matches ) ) {
			foreach ( $matches[1] as $json ) {
				$decoded = json_decode( (string) $json, true );
				if ( ! is_array( $decoded ) ) {
					continue;
				}
				$href = (string) ( $decoded['url'] ?? '' );
				if ( '' === $href ) {
					continue;
				}
				$links[] = array(
					'href'     => $href,
					'anchor'   => (string) ( $decoded['label'] ?? '' ),
					'kind'     => 'navigation',
					'verified' => false,
				);
			}
		}
		return $links;
	}
}
