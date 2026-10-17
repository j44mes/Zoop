<?php
/**
 * Sanitised writes of per-post SEO fields.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Storage;

/**
 * One write path, so no caller stores unsanitised titles or descriptions.
 */
final class SeoMeta {
	/**
	 * Save one validated SEO field with the editor's sanitising. The REST editor, the settings
	 * import and the importers all write through here.
	 */
	public static function store( int $postId, string $metaKey, mixed $value ): void {
		$empty = ( is_string( $value ) && '' === trim( $value ) ) || ( is_int( $value ) && 0 === $value && '_seostudio_og_image' === $metaKey );
		if ( $empty ) {
			delete_post_meta( $postId, $metaKey );
			return;
		}

		if ( '_seostudio_is_news' === $metaKey ) {
			if ( empty( $value ) ) {
				delete_post_meta( $postId, $metaKey );
				return;
			}
			update_post_meta( $postId, $metaKey, '1' );
			return;
		}

		if ( is_string( $value ) && '_seostudio_description' === $metaKey ) {
			$value = sanitize_textarea_field( $value );
		} elseif ( is_string( $value ) && in_array( $metaKey, array( '_seostudio_canonical', '_seostudio_video_url' ), true ) ) {
			$value = esc_url_raw( $value );
		} elseif ( is_string( $value ) ) {
			$value = sanitize_text_field( $value );
		}

		update_post_meta( $postId, $metaKey, $value );
	}
}
