<?php
/**
 * Classic editor meta box.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Admin;

use SEOStudio\Core\HomepageMetadata;
use SEOStudio\Output\HeadOutput;

/**
 * Mounts the page panel below the classic editor.
 */
final class MetaBox {
	/**
	 * Open the classic meta box when the editor URL asks for it.
	 *
	 * Browser acceptance uses this so the classic panel can be tested without another plugin.
	 * The flag does not change stored content.
	 *
	 * @param mixed $block_editor Whether the block editor would be used.
	 * @param mixed $post         Post being edited.
	 */
	public static function preferClassic( mixed $block_editor, mixed $post ): bool {
		if ( self::classicRequested() && $post instanceof \WP_Post && current_user_can( 'edit_post', $post->ID ) ) {
			return false;
		}

		return (bool) $block_editor;
	}

	/**
	 * Same editor choice for a new post, before a post id exists.
	 *
	 * @param mixed  $block_editor Whether the block editor would be used.
	 * @param string $post_type    Post type. Unused.
	 */
	public static function preferClassicType( mixed $block_editor, string $post_type ): bool {
		unset( $post_type );
		if ( self::classicRequested() && current_user_can( 'edit_posts' ) ) {
			return false;
		}

		return (bool) $block_editor;
	}

	/**
	 * Whether this request asked for the classic editor shell.
	 */
	private static function classicRequested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display preference. It does not change stored data.
		$raw = isset( $_GET['seostudio_editor'] ) ? wp_unslash( $_GET['seostudio_editor'] ) : '';

		return 'classic' === sanitize_key( is_scalar( $raw ) ? (string) $raw : '' );
	}

	public static function register( string $post_type, \WP_Post $post ): void {
		if ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
			return;
		}

		add_meta_box(
			'seostudio',
			__( 'Zoop', 'seo-studio' ),
			array( self::class, 'render' ),
			$post_type,
			'normal',
			'high'
		);
	}

	public static function render( \WP_Post $post ): void {
		echo '<div id="seostudio-metabox"></div>';
		wp_nonce_field( 'seostudio_metabox', 'seostudio_metabox_nonce' );
		$robots = get_post_meta( $post->ID, '_seostudio_robots', true );
		$index  = self::robotOn( $robots, 'index' );
		$follow = self::robotOn( $robots, 'follow' );
		if ( ( new HeadOutput() )->publishing() && ! HomepageMetadata::isStaticPage( $post->ID ) ) {
			self::hidden( 'seostudio-stored-title', '_seostudio_title', (string) get_post_meta( $post->ID, '_seostudio_title', true ) );
			self::hidden( 'seostudio-stored-description', '_seostudio_description', (string) get_post_meta( $post->ID, '_seostudio_description', true ) );
		}
		self::hidden( 'seostudio-canonical-field', '_seostudio_canonical', (string) get_post_meta( $post->ID, '_seostudio_canonical', true ) );
		self::hidden( 'seostudio-social-title', '_seostudio_og_title', (string) get_post_meta( $post->ID, '_seostudio_og_title', true ) );
		self::hidden( 'seostudio-social-description', '_seostudio_og_description', (string) get_post_meta( $post->ID, '_seostudio_og_description', true ) );
		self::hidden( 'seostudio-focus-keyphrase', '_seostudio_focus_keyphrase', (string) get_post_meta( $post->ID, '_seostudio_focus_keyphrase', true ) );
		self::hidden( 'seostudio-video-url', '_seostudio_video_url', (string) get_post_meta( $post->ID, '_seostudio_video_url', true ) );
		self::hidden( 'seostudio-related', 'seostudio_related_keyphrases', (string) wp_json_encode( self::listMeta( $post->ID, '_seostudio_related_keyphrases' ) ) );
		self::hidden( 'seostudio-synonyms', 'seostudio_synonyms', (string) wp_json_encode( self::listMeta( $post->ID, '_seostudio_synonyms' ) ) );
		self::hidden( 'seostudio-robots-index', 'seostudio_robots_index', $index ? '1' : '0' );
		self::hidden( 'seostudio-robots-follow', 'seostudio_robots_follow', $follow ? '1' : '0' );
		$news = get_post_meta( $post->ID, '_seostudio_is_news', true );
		self::hidden( 'seostudio-is-news', 'seostudio_is_news', ( '1' === $news || true === $news ) ? '1' : '0' );
	}

	/**
	 * Whether a stored robot flag is on. False, 0, and "0" stay off.
	 *
	 * @param mixed $robots
	 */
	private static function robotOn( $robots, string $key ): bool {
		if ( ! is_array( $robots ) || ! array_key_exists( $key, $robots ) ) {
			return true;
		}
		$value = $robots[ $key ];
		return false !== $value && 0 !== $value && '0' !== $value;
	}

	/**
	 * Stored list, or an empty list when the meta is missing.
	 *
	 * @return array<int, mixed>
	 */
	private static function listMeta( int $postId, string $key ): array {
		$value = get_post_meta( $postId, $key, true );
		return is_array( $value ) ? $value : array();
	}

	private static function hidden( string $id, string $name, string $value ): void {
		printf(
			'<input type="hidden" id="%1$s" name="%2$s" value="%3$s" />',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $value )
		);
	}
}
