<?php
/**
 * Plain-English explanations for a refused published-page fetch.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Content;

/**
 * The stored sentence is the same one the editor sees. It never includes the response body.
 */
final class FetchFailure {
	/**
	 * Message for one blocked fetch. Retention is added only when an older result still exists.
	 */
	public static function message( FetchBlockedException $exception, bool $retained ): string {
		$text = self::attempt( $exception );
		if ( $retained ) {
			$text .= ' ' . __( 'The latest attempt failed. The previous result has been kept.', 'seo-studio' );
		}

		return $text;
	}

	/**
	 * Sentence for the attempt itself, without referring to an older snapshot.
	 */
	public static function attempt( FetchBlockedException $exception ): string {
		$status = $exception->status;
		if ( 'protection_page' === $exception->reason && is_int( $status ) ) {
			return sprintf(
				/* translators: %d: HTTP status returned with a host protection page. */
				__( "Couldn't check the published page. Your host returned a protection page (HTTP %d). Password protection or a bot check may be blocking Zoop.", 'seo-studio' ),
				$status
			);
		}

		if ( 'http_status' === $exception->reason && is_int( $status ) ) {
			if ( 403 === $status ) {
				return __( "Couldn't check the published page. The host refused the request (HTTP 403).", 'seo-studio' );
			}

			return sprintf(
				/* translators: %d: HTTP status returned by the host. */
				__( "Couldn't check the published page. The host responded with HTTP %d, so the page could not be verified.", 'seo-studio' ),
				$status
			);
		}

		if ( 'transport_error' === $exception->reason ) {
			return __( "Couldn't check the published page. Zoop could not reach the page.", 'seo-studio' );
		}

		if ( 'unsafe_target' === $exception->reason ) {
			return __( "Couldn't check the published page. Zoop refused the address because it is not a safe target on this site.", 'seo-studio' );
		}

		if ( 'response_too_large' === $exception->reason || 'oversized' === $exception->reason ) {
			return __( "Couldn't check the published page. The response was larger than Zoop can read.", 'seo-studio' );
		}

		return __( "Couldn't check the published page. The response was not a page Zoop could read.", 'seo-studio' );
	}
}
