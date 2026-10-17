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
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		$hooks = new self();
		add_action( 'added_post_meta', array( $hooks, 'saved' ), 10, 4 );
		add_action( 'updated_post_meta', array( $hooks, 'saved' ), 10, 4 );
		add_action( 'deleted_post_meta', array( $hooks, 'deleted' ), 10, 4 );
		add_action( 'before_delete_post', array( $hooks, 'forgetPost' ) );
	}

	public function saved( int $metaId, int $postId, string $metaKey, mixed $metaValue ): void {
		unset( $metaId );
		if ( self::KEY !== $metaKey ) {
			return;
		}

		( new KeyphrasesIndex() )->replacePrimary( $postId, is_string( $metaValue ) ? $metaValue : '' );
	}

	public function deleted( mixed $metaIds, int $postId, string $metaKey, mixed $metaValue ): void {
		unset( $metaIds, $metaValue );
		if ( self::KEY !== $metaKey ) {
			return;
		}

		( new KeyphrasesIndex() )->replacePrimary( $postId, '' );
	}

	public function forgetPost( int $postId ): void {
		( new KeyphrasesIndex() )->deleteObject( $postId );
	}
}
