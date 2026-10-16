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
}
