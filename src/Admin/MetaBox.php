<?php
/**
 * Classic editor meta box.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Admin;

/**
 * Mounts the page panel below the classic editor.
 */
final class MetaBox {
	public static function register( string $post_type, \WP_Post $post ): void {
		if ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			return;
		}

		add_meta_box(
			'seostudio',
			__( 'SEO', 'seo-studio' ),
			array( self::class, 'render' ),
			$post_type,
			'normal',
			'high'
		);
	}

	public static function render(): void {
		echo '<div id="seostudio-metabox"></div>';
		wp_nonce_field( 'seostudio_metabox', 'seostudio_metabox_nonce' );
	}
}
