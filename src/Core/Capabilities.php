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
			$caps = $type->cap;
			if ( ! current_user_can( $caps->edit_posts ) ) {
				continue;
			}
			$published = current_user_can( $caps->edit_published_posts );
			$parts     = array( "({$alias}.post_author = {$user}" . ( $published ? ')' : " AND NOT {$live})" ) );
			if ( current_user_can( $caps->edit_others_posts ) ) {
				$parts[] = "({$alias}.post_author <> {$user}"
					. ( $published ? '' : " AND NOT {$live}" )
					. ( current_user_can( $caps->edit_private_posts ) ? '' : " AND {$alias}.post_status <> 'private'" )
					. ')';
			}
			$clauses[] = (string) $wpdb->prepare( "({$alias}.post_type = %s AND (" . implode( ' OR ', $parts ) . '))', $type->name ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Alias and capability parts are internal.
		}

		if ( array() === $clauses ) {
			return '1 = 0';
		}

		return '(' . implode( ' OR ', $clauses ) . ") AND {$alias}.post_status NOT IN ('trash', 'auto-draft', 'inherit')";
	}
}
