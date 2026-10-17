<?php
/**
 * Keeps the keyphrase index aligned with post meta.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Storage;

/**
 * Meta writes and post deletion update seostudio_keyphrases.
 */
final class KeyphraseHooks {
	private const KEY = '_seostudio_focus_keyphrase';

	public static function register(): void {
		static $hooks = null;
		$hooks      ??= new self();
		foreach ( array(
			'added_post_meta'    => 'saved',
			'updated_post_meta'  => 'saved',
			'deleted_post_meta'  => 'deleted',
			'before_delete_post' => 'forgetPost',
		) as $hook => $method ) {
			remove_action( $hook, array( $hooks, $method ), 10 );
			add_action( $hook, array( $hooks, $method ), 10, 4 );
		}
	}

	public function saved( int $metaId, int $postId, string $metaKey, mixed $metaValue ): void {
		unset( $metaId, $metaValue );
		if ( ! in_array( $metaKey, array( self::KEY, '_seostudio_related_keyphrases' ), true ) ) {
			return;
		}

		( new KeyphrasesIndex() )->syncPost( $postId );
		if ( self::KEY === $metaKey ) {
			( new PagesRepository() )->syncFocus( $postId );
		}
	}

	public function deleted( mixed $metaIds, int $postId, string $metaKey, mixed $metaValue ): void {
		unset( $metaIds, $metaValue );
		if ( ! in_array( $metaKey, array( self::KEY, '_seostudio_related_keyphrases' ), true ) ) {
			return;
		}

		( new KeyphrasesIndex() )->syncPost( $postId );
		if ( self::KEY === $metaKey ) {
			( new PagesRepository() )->syncFocus( $postId );
		}
	}

	public function forgetPost( int $postId ): void {
		( new KeyphrasesIndex() )->deleteObject( $postId );
	}
}
