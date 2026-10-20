<?php
/**
 * Drops definitive published-page readiness when source inputs change.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Storage;

use SEOStudio\Content\SourceFingerprint;
use SEOStudio\Integrations\Detector;

/**
 * Hooks record the change. They do not fetch the published page.
 */
final class PublishedFreshness {
	public static function register(): void {
		add_action( 'save_post', array( self::class, 'savedPost' ), 30, 2 );
		add_action( 'rest_after_insert_post', array( self::class, 'savedRest' ), 30, 1 );
		add_action( 'rest_after_insert_page', array( self::class, 'savedRest' ), 30, 1 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( self::class, 'savedMeta' ), 30, 4 );
		}
		add_action( 'switch_theme', array( self::class, 'siteChanged' ) );
		add_action( 'customize_save_after', array( self::class, 'siteChanged' ) );
		add_action( 'upgrader_process_complete', array( self::class, 'siteChanged' ) );
		add_action( 'update_option_blog_public', array( self::class, 'siteChanged' ) );
		add_action( 'update_option_' . \SEOStudio\Core\Settings::OPTION, array( self::class, 'siteChanged' ) );
		add_action( 'activated_plugin', array( self::class, 'pluginChanged' ) );
		add_action( 'deactivated_plugin', array( self::class, 'pluginChanged' ) );
	}

	/**
	 * Invalidate one saved post.
	 *
	 * @param mixed $post Post object. Unused.
	 */
	public static function savedPost( int $postId, mixed $post ): void {
		unset( $post );
		if ( wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) ) {
			return;
		}
		self::postChanged( $postId );
	}

	/**
	 * REST can write meta after save_post. Check the post again once insert finishes.
	 *
	 * @param mixed $post
	 */
	public static function savedRest( mixed $post ): void {
		if ( $post instanceof \WP_Post ) {
			self::postChanged( (int) $post->ID );
		}
	}

	public static function savedMeta( mixed $metaId, int $postId, string $metaKey, mixed $metaValue = null ): void {
		unset( $metaId, $metaValue );
		if ( in_array( $metaKey, SourceFingerprint::metaKeys(), true ) ) {
			self::postChanged( $postId );
		}
	}

	public static function pluginChanged( string $plugin ): void {
		$slug = dirname( $plugin );
		foreach ( ( new Detector() )->catalogue() as $known ) {
			if ( $known['slug'] === $slug || str_contains( $plugin, $known['slug'] ) ) {
				self::siteChanged();
				return;
			}
		}
	}

	public static function siteChanged(): void {
		( new PagesRepository() )->withholdPublishedSnapshots();
	}

	public static function postChanged( int $postId ): void {
		( new PagesRepository() )->withholdIfSourceChanged( $postId );
	}
}
