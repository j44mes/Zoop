<?php
/**
 * Capability checks.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Core;

/**
 * Object-level permissions used by REST routes.
 */
final class Capabilities {
	public static function canEditPost( int $postId ): bool {
		return current_user_can( 'edit_post', $postId );
	}

	public static function canEditPosts(): bool {
		return current_user_can( 'edit_posts' );
	}

	public static function canManage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * SQL is only an optimisation for standard, unfiltered capability mappings.
	 */
	public static function needsObjectChecks(): bool {
		global $wp_filter;

		if ( false !== has_filter( 'map_meta_cap' ) ) {
			return true;
		}
		// These core filters only grant language, recovery and site-health capabilities.
		$core = array( 'wp_maybe_grant_install_languages_cap', 'wp_maybe_grant_resume_extensions_caps', 'wp_maybe_grant_site_health_caps' );
		foreach ( $wp_filter['user_has_cap']->callbacks ?? array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( ! in_array( $callback['function'], $core, true ) ) {
					return true;
				}
			}
		}
		foreach ( get_post_types( array(), 'objects' ) as $type ) {
			if ( ! $type->_builtin || ( $type->show_ui && ! $type->map_meta_cap ) ) {
				return true;
			}
		}

		return array() !== get_post_stati( array( '_builtin' => false ) );
	}

	/**
	 * SQL condition for posts the current user may edit, mirroring map_meta_cap( 'edit_post' )
	 * per post type, so a list can be counted and paged in the database. Callers still run
	 * canEditPost() on the rows they show, which applies custom map_meta_cap filters.
	 */
	public static function editableSql( string $alias ): string {
		global $wpdb;

		$user    = get_current_user_id();
		$live    = "{$alias}.post_status IN ('publish', 'future')";
		$clauses = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
			$caps      = $type->cap;
			$published = current_user_can( $caps->edit_published_posts );
			$parts     = array();
			if ( current_user_can( $caps->edit_posts ) ) {
				$parts[] = "({$alias}.post_author = {$user} AND NOT {$live})";
			}
			if ( $published ) {
				$parts[] = "({$alias}.post_author = {$user} AND {$live})";
			}
			if ( current_user_can( $caps->edit_others_posts ) ) {
				$parts[] = "({$alias}.post_author <> {$user}"
					. ( $published ? '' : " AND NOT {$live}" )
					. ( current_user_can( $caps->edit_private_posts ) ? '' : " AND {$alias}.post_status <> 'private'" )
					. ')';
			}
			if ( array() === $parts ) {
				continue;
			}
			$clauses[] = (string) $wpdb->prepare( "({$alias}.post_type = %s AND (" . implode( ' OR ', $parts ) . '))', $type->name ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Alias and capability parts are internal.
		}

		if ( array() === $clauses ) {
			return '1 = 0';
		}

		$sql = '(' . implode( ' OR ', $clauses ) . ") AND {$alias}.post_status NOT IN ('trash', 'auto-draft', 'inherit')";
		if ( ! current_user_can( 'manage_privacy_options' ) ) {
			$sql .= ' AND ' . $alias . '.ID <> ' . (int) get_option( 'wp_page_for_privacy_policy' );
		}
		return $sql;
	}
}
