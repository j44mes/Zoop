<?php
/**
 * Freshness of stored published-page links.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Architecture;

/**
 * A link observation is fresh without a focus keyphrase or an on-page score.
 */
final class LinkSource {
	/**
	 * Hash the inputs that can change published links. The focus keyphrase is not included.
	 *
	 * @param string|null $navigation Precomputed navigation fingerprint, when the caller already has one.
	 */
	public static function current( int $postId, ?string $navigation = null ): string {
		$post = function_exists( 'get_post' ) ? get_post( $postId ) : null;
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}
		$theme   = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
		$parts   = array(
			'content'    => (string) $post->post_content,
			'theme'      => $theme ? $theme->get_stylesheet() . '@' . (string) $theme->get( 'Version' ) : '',
			'navigation' => null === $navigation ? self::navigationFingerprint() : $navigation,
		);
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $parts ) : self::encode( $parts );

		return md5( is_string( $encoded ) ? $encoded : '' );
	}

	/**
	 * Assigned menus and referenced block navigation. Unassigned menus are omitted.
	 */
	public static function navigationFingerprint(): string {
		$parts   = array(
			'classic' => self::classicMenuParts(),
			'blocks'  => self::blockNavigationParts()['parts'],
		);
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $parts ) : self::encode( $parts );

		return md5( is_string( $encoded ) ? $encoded : '' );
	}

	/**
	 * Whether a block-navigation change might not be visible in the fingerprint.
	 */
	public static function navigationLimitation(): string {
		$blocks = self::blockNavigationParts();
		if ( ! empty( $blocks['limited'] ) ) {
			return __( 'A navigation block has no stored reference. Zoop uses links from fresh published pages and does not invent menu edges. Orphan counts wait until those pages have been checked.', 'seo-studio' );
		}
		return '';
	}

	/**
	 * A stored live model is fresh when its link hash still matches.
	 *
	 * @param array<string, mixed> $model       Decoded page model.
	 * @param string|null          $navigation  Precomputed navigation fingerprint.
	 */
	public static function isFresh( array $model, int $postId, ?string $navigation = null ): bool {
		if ( 'live' !== (string) ( $model['render_mode'] ?? '' ) ) {
			return false;
		}
		$stored = (string) ( $model['link_source_hash'] ?? '' );
		if ( '' === $stored ) {
			return false;
		}
		$current = self::current( $postId, $navigation );
		return '' !== $current && hash_equals( $stored, $current );
	}

	/**
	 * Assigned classic menu locations and their item addresses.
	 *
	 * @return string[]
	 */
	private static function classicMenuParts(): array {
		if ( ! function_exists( 'get_nav_menu_locations' ) || ! function_exists( 'wp_get_nav_menu_items' ) ) {
			return array();
		}
		$locations = get_nav_menu_locations();
		if ( ! is_array( $locations ) ) {
			return array();
		}
		ksort( $locations );
		$parts = array();
		foreach ( $locations as $location => $menu_id ) {
			$menu_id = (int) $menu_id;
			$hrefs   = array();
			$items   = $menu_id > 0 ? wp_get_nav_menu_items( $menu_id ) : array();
			if ( is_array( $items ) ) {
				foreach ( $items as $item ) {
					if ( is_object( $item ) ) {
						$hrefs[] = (string) ( $item->url ?? '' );
					}
				}
			}
			$parts[] = (string) $location . '#' . $menu_id . '#' . implode( '|', $hrefs );
		}
		return $parts;
	}

	/**
	 * Content hashes for navigation posts the active templates reference.
	 *
	 * @return array{parts: string[], limited: bool}
	 */
	private static function blockNavigationParts(): array {
		$found = self::referencedBlockNavigation();
		$parts = array();
		foreach ( $found['ids'] as $id ) {
			$post    = function_exists( 'get_post' ) ? get_post( $id ) : null;
			$content = $post instanceof \WP_Post ? (string) $post->post_content : '';
			$parts[] = $id . '#' . md5( $content );
		}
		return array(
			'parts'   => $parts,
			'limited' => $found['limited'],
		);
	}

	/**
	 * Navigation posts referenced by the active block templates.
	 *
	 * @return array{ids: int[], inline: list<array{href: string, anchor: string}>, limited: bool}
	 */
	public static function referencedBlockNavigation(): array {
		if ( ! function_exists( 'get_block_templates' ) ) {
			return array(
				'ids'     => array(),
				'inline'  => array(),
				'limited' => false,
			);
		}
		$ids       = array();
		$inline    = array();
		$limited   = false;
		$templates = array_merge(
			get_block_templates( array(), 'wp_template' ),
			get_block_templates( array(), 'wp_template_part' )
		);
		foreach ( $templates as $template ) {
			$content  = is_object( $template ) ? (string) $template->content : '';
			$expanded = self::expandPatterns( $content );
			$limited  = $limited || $expanded['limited'];
			$scan     = self::scanNavigation( $expanded['content'] );
			$ids      = array_merge( $ids, $scan['ids'] );
			$inline   = array_merge( $inline, $scan['inline'] );
			$limited  = $limited || $scan['limited'];
		}
		return array(
			'ids'     => array_values( array_unique( $ids ) ),
			'inline'  => $inline,
			'limited' => $limited,
		);
	}

	/**
	 * Inline registered pattern markup so a navigation block inside a pattern is visible.
	 *
	 * @return array{content: string, limited: bool}
	 */
	private static function expandPatterns( string $content, int $depth = 0 ): array {
		if ( ! str_contains( $content, 'wp:pattern' ) ) {
			return array(
				'content' => $content,
				'limited' => false,
			);
		}
		if ( $depth > 4 || ! class_exists( \WP_Block_Patterns_Registry::class ) ) {
			return array(
				'content' => $content,
				'limited' => true,
			);
		}
		$limited  = false;
		$expanded = preg_replace_callback(
			'/<!--\s*wp:pattern\s+(\{.*?\})\s*\/-->/s',
			static function ( array $found ) use ( &$limited, $depth ): string {
				$decoded  = json_decode( $found[1], true );
				$slug     = is_array( $decoded ) ? (string) ( $decoded['slug'] ?? '' ) : '';
				$registry = \WP_Block_Patterns_Registry::get_instance();
				if ( '' === $slug || ! $registry->is_registered( $slug ) ) {
					$limited = true;
					return '';
				}
				$pattern = $registry->get_registered( $slug );
				$inner   = is_array( $pattern ) ? (string) ( $pattern['content'] ?? '' ) : '';
				if ( '' === $inner ) {
					$limited = true;
					return '';
				}
				$nested  = self::expandPatterns( $inner, $depth + 1 );
				$limited = $limited || $nested['limited'];
				return $nested['content'];
			},
			$content
		);

		return array(
			'content' => is_string( $expanded ) ? $expanded : $content,
			'limited' => $limited,
		);
	}

	/**
	 * Read navigation block references and inline links out of template markup.
	 *
	 * A block without a stored ref is uncertain only when it also has no inline links.
	 * Fresh published HTML can still establish those links.
	 *
	 * @return array{ids: int[], inline: list<array{href: string, anchor: string}>, limited: bool}
	 */
	private static function scanNavigation( string $content ): array {
		$ids     = array();
		$inline  = array();
		$limited = false;
		if ( ! preg_match_all( '/<!--\s*wp:navigation\b([^>]*?)(\/)?-->/', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array(
				'ids'     => array(),
				'inline'  => array(),
				'limited' => false,
			);
		}
		foreach ( $matches[0] as $index => $found ) {
			$attrs    = (string) ( $matches[1][ $index ][0] ?? '' );
			$is_empty = '/' === (string) ( $matches[2][ $index ][0] ?? '' );
			if ( preg_match( '/"ref"\s*:\s*(\d+)/', $attrs, $ref ) ) {
				$ids[] = (int) $ref[1];
				continue;
			}
			$inner = '';
			if ( ! $is_empty ) {
				$start = (int) $found[1] + strlen( (string) $found[0] );
				$end   = strpos( $content, '<!-- /wp:navigation', $start );
				$inner = false === $end ? '' : substr( $content, $start, $end - $start );
			}
			$links = self::inlineNavigationLinks( $inner );
			if ( array() === $links ) {
				$limited = true;
				continue;
			}
			$inline = array_merge( $inline, $links );
		}
		return array(
			'ids'     => $ids,
			'inline'  => $inline,
			'limited' => $limited,
		);
	}

	/**
	 * Navigation-link blocks written inside a navigation block.
	 *
	 * @return list<array{href: string, anchor: string}>
	 */
	private static function inlineNavigationLinks( string $content ): array {
		$links = array();
		if ( ! preg_match_all( '/wp:navigation-link\s+(\{.*?\})/s', $content, $matches ) ) {
			return array();
		}
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
				'href'   => $href,
				'anchor' => (string) ( $decoded['label'] ?? '' ),
			);
		}

		return $links;
	}

	/**
	 * Encode a fingerprint outside WordPress, where wp_json_encode() is absent.
	 *
	 * @param array<string, mixed> $parts Fingerprint parts.
	 */
	private static function encode( array $parts ): string {
		$encoded = json_encode( $parts ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() is unavailable in unit tests.
		return is_string( $encoded ) ? $encoded : '';
	}
}
