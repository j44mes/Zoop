<?php
/**
 * Site snapshot for the preservation guarantee.
 *
 * Library use: the regression test takes a snapshot before and after an operation and compares them.
 * Staging use: wp eval-file tests/regression/snapshot.php before.json, then the same after the
 * operation with after.json, then diff the two files. Run it from the plugin directory.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Regression;

/**
 * Permalinks, post IDs, content hashes, sample head tags, the sitemap and other plugins' post meta.
 */
final class Snapshot {
	/**
	 * Tags Zoop prints in Owner mode. Only these head lines may change.
	 */
	public const OWN_TAGS = '/^<(?:title>|meta name="description"|link rel="canonical"|meta (?:property|name)="(?:og|twitter):|script type="application\/ld\+json"|meta name=\'robots\')/';

	/**
	 * Capture the site. Heads are rendered in-process for the first $sample published URLs.
	 *
	 * @return array{posts: array<int, array<string, string>>, heads: array<string, string[]>, sitemap: string[], meta: array<int, string>}
	 */
	public static function take( int $sample = 20 ): array {
		global $wpdb;

		$posts = array();
		$rows  = $wpdb->get_results( "SELECT ID, post_type, post_status, post_name, post_content FROM {$wpdb->posts} WHERE post_type <> 'revision' ORDER BY ID", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$posts[ (int) $row['ID'] ] = array(
				'type'      => (string) $row['post_type'],
				'status'    => (string) $row['post_status'],
				'slug'      => (string) $row['post_name'],
				'content'   => md5( (string) $row['post_content'] ),
				'permalink' => (string) get_permalink( (int) $row['ID'] ),
			);
		}

		$meta = array();
		$rows = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key NOT LIKE '\\_seostudio\\_%' AND meta_key NOT IN ('_edit_lock', '_edit_last') ORDER BY post_id, meta_key, meta_id", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id          = (int) $row['post_id'];
			$meta[ $id ] = md5( ( $meta[ $id ] ?? '' ) . $row['meta_key'] . '=' . $row['meta_value'] );
		}

		$heads = array();
		$ids   = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => max( 0, $sample - 1 ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		foreach ( array_merge( array( (string) home_url( '/' ) ), array_map( 'get_permalink', $ids ) ) as $url ) {
			$heads[ (string) $url ] = self::head( (string) $url );
		}

		return array(
			'posts'   => $posts,
			'heads'   => $heads,
			'sitemap' => self::sitemap(),
			'meta'    => $meta,
		);
	}

	/**
	 * Differences between two snapshots. In Owner mode, changed head lines must be Zoop's own tags.
	 *
	 * @param array<string, mixed> $before
	 * @param array<string, mixed> $after
	 * @return string[]
	 */
	public static function diff( array $before, array $after, bool $ownerMode = false ): array {
		$changes = array();
		foreach ( array( 'posts', 'sitemap', 'meta' ) as $part ) {
			if ( $before[ $part ] !== $after[ $part ] ) {
				$changes[] = $part . ' changed';
			}
		}

		foreach ( $before['heads'] as $url => $lines ) {
			$then = $ownerMode ? self::foreign( $lines ) : $lines;
			$now  = $ownerMode ? self::foreign( $after['heads'][ $url ] ?? array() ) : ( $after['heads'][ $url ] ?? array() );
			foreach ( array_diff( $then, $now ) as $line ) {
				$changes[] = 'head of ' . $url . ' lost: ' . $line;
			}
			foreach ( array_diff( $now, $then ) as $line ) {
				$changes[] = 'head of ' . $url . ' gained: ' . $line;
			}
		}

		return $changes;
	}

	/**
	 * Head lines Zoop does not own.
	 *
	 * @param string[] $lines
	 * @return string[]
	 */
	private static function foreign( array $lines ): array {
		return array_values( array_filter( $lines, static fn ( string $line ): bool => 1 !== preg_match( self::OWN_TAGS, $line ) ) );
	}

	/**
	 * The rendered wp_head for one URL, one tag per line.
	 *
	 * @return string[]
	 */
	private static function head( string $url ): array {
		global $wp_query, $wp_the_query, $wp, $wp_filter, $wp_scripts, $wp_styles;

		// Each render behaves like a fresh request: hooks removed during one head (Owner mode
		// removes rel_canonical) come back, and every script and style prints again.
		$saved      = array( $wp_query, $wp_the_query, $_SERVER['REQUEST_URI'] ?? '' );
		$hook       = isset( $wp_filter['wp_head'] ) ? clone $wp_filter['wp_head'] : null;
		$wp_scripts = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Fresh registries per rendered URL.
		$wp_styles  = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- As above.
		$path       = wp_parse_url( $url, PHP_URL_PATH );
		$query      = wp_parse_url( $url, PHP_URL_QUERY );

		$_SERVER['REQUEST_URI'] = ( is_string( $path ) ? $path : '/' ) . ( is_string( $query ) ? '?' . $query : '' );
		$wp_the_query           = new \WP_Query();
		$wp_query               = $wp_the_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Rendering a URL in-process needs the main query.
		$wp                     = new \WP(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- As above.
		parse_str( is_string( $query ) ? $query : '', $vars );
		$wp->main( $vars );

		ob_start();
		wp_head();
		$html = (string) ob_get_clean();

		list( $wp_query, $wp_the_query, $_SERVER['REQUEST_URI'] ) = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the globals saved above.
		if ( null !== $hook ) {
			$wp_filter['wp_head'] = $hook; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the hook saved above.
		}

		// Compare head tags. Inline CSS and script bodies are skipped: some core output, such as
		// the emoji styles, prints once per process and would differ between in-process renders.
		$lines = preg_split( '/\R/', (string) preg_replace( '/>\s*</', ">\n<", $html ) );
		$lines = array_map( 'trim', is_array( $lines ) ? $lines : array() );
		return array_values(
			array_filter(
				$lines,
				static fn ( string $line ): bool => 1 === preg_match( '/^<(?:title>|meta |link |script type="application\/ld\+json")/', $line )
			)
		);
	}

	/**
	 * Sitemap URLs for every provider and subtype.
	 *
	 * @return string[]
	 */
	private static function sitemap(): array {
		$server = wp_sitemaps_get_server();
		$urls   = array();
		foreach ( $server->registry->get_providers() as $provider ) {
			foreach ( array_keys( $provider->get_object_subtypes() ?: array( '' => null ) ) as $subtype ) {
				foreach ( $provider->get_url_list( 1, (string) $subtype ) as $entry ) {
					$urls[] = (string) ( $entry['loc'] ?? '' );
				}
			}
		}
		sort( $urls );
		return $urls;
	}
}

// Staging use through WP-CLI: write the snapshot to the file named in the first argument.
if ( defined( 'WP_CLI' ) && WP_CLI && isset( $args[0] ) && is_string( $args[0] ) ) {
	file_put_contents( $args[0], (string) wp_json_encode( Snapshot::take(), JSON_PRETTY_PRINT ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI output file.
	\WP_CLI::success( 'Snapshot written to ' . $args[0] );
}
