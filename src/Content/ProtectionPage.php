<?php
/**
 * Detects host protection pages before they can be scored.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Content;

/**
 * A challenge is recognised only from high-confidence markers.
 *
 * Missing titles or main elements are not enough. A normal page may mention CAPTCHA.
 */
final class ProtectionPage {
	/**
	 * Whether the body is a bot-check or interstitial rather than the published page.
	 */
	public static function isChallenge( string $body ): bool {
		$sample  = strtolower( substr( $body, 0, 65536 ) );
		$markers = array(
			'sgcaptcha',
			'sg-captcha',
			'/.well-known/sgcaptcha',
			'cf-browser-verification',
			'cf-challenge',
			'challenge-platform',
			'attention required! | cloudflare',
		);
		foreach ( $markers as $marker ) {
			if ( str_contains( $sample, $marker ) ) {
				return true;
			}
		}

		return str_contains( $sample, 'just a moment' ) && str_contains( $sample, 'cloudflare' );
	}

	/**
	 * Whether a successful response is not a document Zoop can read.
	 *
	 * An absent content type is treated as a document. Images and other files are not.
	 */
	public static function isUnreadable( string $body, string $contentType ): bool {
		if ( '' === trim( $body ) ) {
			return true;
		}

		$split = strtok( $contentType, ';' );
		$type  = strtolower( trim( is_string( $split ) ? $split : '' ) );
		if ( '' === $type || str_contains( $type, 'html' ) || str_contains( $type, 'xml' ) || 'text/plain' === $type ) {
			return false;
		}

		return true;
	}
}
