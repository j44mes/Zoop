<?php
/**
 * Plain-English explanations for a refused published-page fetch.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Content;

/**
 * The stored sentence is the attempt itself. Dates and help links are added when the record is read.
 */
final class FetchFailure {
	/**
	 * How an editor lets Zoop through password protection or a host bot check.
	 */
	public const GUIDANCE_URL = 'https://github.com/j44mes/Zoop/blob/main/docs/letting-zoop-through.md';

	/**
	 * Message stored for one blocked fetch. It does not mention an older snapshot.
	 */
	public static function message( FetchBlockedException $exception ): string {
		return self::attempt( $exception );
	}

	/**
	 * Sentence for the attempt itself.
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
				__( "Couldn't check the published page. Your host returned HTTP %d, so Zoop couldn't confirm what's on the page.", 'seo-studio' ),
				$status
			);
		}

		if ( 'transport_error' === $exception->reason ) {
			return __( "Couldn't reach the published page. Check the site is online and try again.", 'seo-studio' );
		}

		if ( 'unsafe_target' === $exception->reason ) {
			return __( "Couldn't check the published page. Zoop refused the address because it is not a safe target on this site.", 'seo-studio' );
		}

		if ( 'response_too_large' === $exception->reason || 'oversized' === $exception->reason ) {
			return __( "Couldn't check the published page. The response was larger than Zoop can read.", 'seo-studio' );
		}

		return __( "Couldn't check the published page. The response was not a page Zoop could read.", 'seo-studio' );
	}

	/**
	 * Sentence shown when a failed check left an earlier successful result in place.
	 *
	 * Both dates are already formatted with the site date format. An empty date omits the sentence.
	 */
	public static function retainedNotice( string $successfulOn, string $failedOn ): string {
		if ( '' === $successfulOn || '' === $failedOn ) {
			return '';
		}

		return sprintf(
			/* translators: 1: date of the last successful published-page check, 2: date of the check that failed. */
			__( 'Last checked successfully on %1$s. The latest check on %2$s failed, so Zoop is still showing the earlier result.', 'seo-studio' ),
			$successfulOn,
			$failedOn
		);
	}

	/**
	 * Format a GMT MySQL datetime with the site's date format.
	 */
	public static function siteDate( string $gmtMysql ): string {
		$gmtMysql = trim( $gmtMysql );
		if ( '' === $gmtMysql || str_starts_with( $gmtMysql, '0000-00-00' ) ) {
			return '';
		}
		if ( ! function_exists( 'wp_date' ) || ! function_exists( 'get_option' ) ) {
			return '';
		}
		$stamp = strtotime( $gmtMysql . ' UTC' );
		if ( false === $stamp ) {
			return '';
		}
		$format = get_option( 'date_format' );
		if ( ! is_string( $format ) || '' === $format ) {
			$format = 'F j, Y';
		}
		$formatted = wp_date( $format, $stamp );

		return is_string( $formatted ) ? $formatted : '';
	}

	/**
	 * Editor copy for a stored page row. Reason codes stay out of both strings.
	 *
	 * @param array<string, mixed> $row Page row.
	 * @return array{retained_notice: string, fetch_guidance_url: string}
	 */
	public static function presentation( array $row ): array {
		$error = trim( (string) ( $row['last_error'] ?? '' ) );
		if ( '' === $error ) {
			return array(
				'retained_notice'    => '',
				'fetch_guidance_url' => '',
			);
		}

		$success = trim( (string) ( $row['last_fetched_at'] ?? '' ) );
		if ( '' === $success ) {
			$success = trim( (string) ( $row['verified_at'] ?? '' ) );
		}
		$reason = (string) ( $row['fetch_failure_reason'] ?? '' );

		return array(
			'retained_notice'    => self::retainedNotice(
				self::siteDate( $success ),
				self::siteDate( (string) ( $row['fetch_attempted_at'] ?? '' ) )
			),
			'fetch_guidance_url' => 'protection_page' === $reason ? self::GUIDANCE_URL : '',
		);
	}
}
