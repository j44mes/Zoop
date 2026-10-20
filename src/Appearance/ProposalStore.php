<?php
/**
 * The only writer of a title and description proposal.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Appearance;

use SEOStudio\Rest\SeoValidator;
use SEOStudio\Storage\SeoMeta;

/**
 * Locks one post, checks the revision, then writes both proposal fields together.
 */
final class ProposalStore {
	public const REVISION = '_seostudio_appearance_revision';

	public const HEADER = 'X-Zoop-Proposal-Revision';

	/**
	 * True while this class writes the revision.
	 *
	 * @var bool
	 */
	private static bool $internal = false;

	public static function isInternalWrite(): bool {
		return self::$internal;
	}

	/**
	 * Block REST or another caller from storing the revision the client sent.
	 *
	 * @param mixed  $check
	 * @param mixed  $meta_value
	 * @return mixed
	 */
	public static function blockExternalRevision( $check, int $object_id, string $meta_key, $meta_value ) {
		unset( $object_id, $meta_value );
		if ( self::REVISION !== $meta_key || self::$internal ) {
			return $check;
		}
		return false;
	}

	/**
	 * Accept a non-negative integer revision token.
	 */
	public static function revisionFrom( mixed $value ): ?int {
		if ( is_int( $value ) && $value >= 0 ) {
			return $value;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\d+$/', $value ) ) {
			return (int) $value;
		}
		return null;
	}

	/**
	 * Save both fields or leave the stored proposal untouched.
	 *
	 * A null field keeps the stored value. An empty string clears it.
	 *
	 * @return array{ok: true, revision: int}|array{ok: false, status: int, code: string, message: string}
	 */
	public function commit( int $postId, ?string $title, ?string $description, int $expectedRevision ): array {
		if ( null !== $title ) {
			$title = sanitize_text_field( $title );
		}
		if ( null !== $description ) {
			$description = sanitize_textarea_field( $description );
		}
		$error = $this->validatePair( $postId, $title, $description );
		if ( null !== $error ) {
			return $error;
		}

		$locked = false;
		try {
			if ( ! $this->lock( $postId ) ) {
				return $this->failure( 409, 'seostudio_stale', __( 'The proposal could not be locked. Save it again.', 'seo-studio' ) );
			}
			$locked = true;
			return $this->writePair( $postId, $title, $description, $expectedRevision );
		} catch ( \Throwable $error ) {
			unset( $error );
			return $this->failure( 500, 'seostudio_write_failed', __( 'The proposal could not be saved.', 'seo-studio' ) );
		} finally {
			if ( $locked ) {
				$this->unlock( $postId );
			}
		}
	}

	/**
	 * Copy an imported published title into the proposal and move the revision forward.
	 */
	public function syncImported( int $postId, ?string $title, ?string $description ): void {
		if ( null === $title && null === $description ) {
			return;
		}
		$locked = false;
		try {
			if ( ! $this->lock( $postId ) ) {
				return;
			}
			$locked  = true;
			$current = $this->revision( $postId );
			$this->writePair( $postId, $title, $description, $current );
		} finally {
			if ( $locked ) {
				$this->unlock( $postId );
			}
		}
	}

	public function revision( int $postId ): int {
		$value = get_post_meta( $postId, self::REVISION, true );
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Reject a pair the validator will not store.
	 *
	 * @return array{ok: false, status: int, code: string, message: string}|null
	 */
	private function validatePair( int $postId, ?string $title, ?string $description ): ?array {
		$next_title       = null === $title ? $this->meta( $postId, Fields::TITLE ) : $title;
		$next_description = null === $description ? $this->meta( $postId, Fields::DESCRIPTION ) : $description;
		$error            = ( new SeoValidator() )->validate(
			array(
				'title'       => $next_title,
				'description' => $next_description,
			),
			(string) home_url( '/' )
		);
		if ( null === $error ) {
			return null;
		}
		return $this->failure( 400, 'seostudio_validation', $error['message'] );
	}

	/**
	 * Write both fields and the next revision, or put the previous pair back.
	 *
	 * The named lock keeps other requests out. It is not a database transaction, so a failed
	 * write copies the pair captured under that lock back over any field that did change.
	 *
	 * @return array{ok: true, revision: int}|array{ok: false, status: int, code: string, message: string}
	 */
	private function writePair( int $postId, ?string $title, ?string $description, int $expectedRevision ): array {
		$current = $this->revision( $postId );
		if ( $current !== $expectedRevision ) {
			return $this->failure( 409, 'seostudio_stale', __( 'A newer proposal was saved. Review it before saving again.', 'seo-studio' ) );
		}

		$previous         = array(
			'title'       => $this->meta( $postId, Fields::TITLE ),
			'description' => $this->meta( $postId, Fields::DESCRIPTION ),
			'revision'    => $this->meta( $postId, self::REVISION ),
			'saved'       => $this->meta( $postId, Fields::SAVED ),
		);
		$next_title       = null === $title ? $previous['title'] : $title;
		$next_description = null === $description ? $previous['description'] : $description;

		try {
			self::$internal = true;
			$title_ok       = $this->store( $postId, Fields::TITLE, $next_title );
			$description_ok = $this->store( $postId, Fields::DESCRIPTION, $next_description );
			$revision_ok    = $this->store( $postId, self::REVISION, (string) ( $current + 1 ) );
			self::$internal = false;
			$saved_ok       = $this->store( $postId, Fields::SAVED, '1' );
			if ( ! $title_ok || ! $description_ok || ! $revision_ok || ! $saved_ok || ! $this->pairMatches( $postId, $next_title, $next_description, $current + 1 ) ) {
				$this->restorePair( $postId, $previous );
				return $this->failure( 500, 'seostudio_write_failed', __( 'The proposal could not be saved.', 'seo-studio' ) );
			}

			return array(
				'ok'       => true,
				'revision' => $current + 1,
			);
		} catch ( \Throwable $error ) {
			unset( $error );
			$this->restorePair( $postId, $previous );
			return $this->failure( 500, 'seostudio_write_failed', __( 'The proposal could not be saved.', 'seo-studio' ) );
		} finally {
			self::$internal = false;
		}
	}

	/**
	 * Put back the pair captured before a failed write.
	 *
	 * @param array{title: string, description: string, revision: string, saved: string} $previous
	 */
	private function restorePair( int $postId, array $previous ): void {
		self::$internal = true;
		try {
			$this->store( $postId, Fields::TITLE, $previous['title'] );
			$this->store( $postId, Fields::DESCRIPTION, $previous['description'] );
			$this->store( $postId, self::REVISION, $previous['revision'] );
			$this->store( $postId, Fields::SAVED, $previous['saved'] );
		} catch ( \Throwable $error ) {
			unset( $error );
		} finally {
			self::$internal = false;
			wp_cache_delete( $postId, 'post_meta' );
		}
	}

	private function pairMatches( int $postId, string $title, string $description, int $revision ): bool {
		return $this->meta( $postId, Fields::TITLE ) === $title
			&& $this->meta( $postId, Fields::DESCRIPTION ) === $description
			&& $this->revision( $postId ) === $revision
			&& '1' === $this->meta( $postId, Fields::SAVED );
	}

	private function store( int $postId, string $key, string $value ): bool {
		$current = $this->meta( $postId, $key );
		if ( $current === $value ) {
			return true;
		}
		if ( '' === $value ) {
			return '' === $current || delete_post_meta( $postId, $key );
		}
		if ( self::REVISION === $key || Fields::SAVED === $key ) {
			return false !== update_post_meta( $postId, $key, $value );
		}
		SeoMeta::store( $postId, $key, $value );
		return $this->meta( $postId, $key ) === $value;
	}

	private function meta( int $postId, string $key ): string {
		$value = get_post_meta( $postId, $key, true );
		return is_string( $value ) ? $value : '';
	}

	private function lock( int $postId ): bool {
		global $wpdb;

		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->lockName( $postId ), 10 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return '1' === (string) $locked;
	}

	private function unlock( int $postId ): void {
		global $wpdb;

		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lockName( $postId ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	private function lockName( int $postId ): string {
		return 'seostudio_proposal_' . $postId;
	}

	/**
	 * Failure result for a proposal that was not stored.
	 *
	 * @return array{ok: false, status: int, code: string, message: string}
	 */
	private function failure( int $status, string $code, string $message ): array {
		return array(
			'ok'      => false,
			'status'  => $status,
			'code'    => $code,
			'message' => $message,
		);
	}
}
