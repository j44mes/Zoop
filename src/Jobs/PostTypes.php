<?php
/**
 * Content types included in automatic work.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Jobs;

/**
 * Public post types for audits, save analysis, and live fetches.
 */
final class PostTypes {
	/**
	 * Public content types, without attachments.
	 *
	 * @return string[]
	 */
	public static function public(): array {
		if ( ! function_exists( 'get_post_types' ) ) {
			return array( 'post', 'page' );
		}

		$types = get_post_types( array( 'public' => true ) );
		if ( ! is_array( $types ) ) {
			return array( 'post', 'page' );
		}

		$names = array();
		foreach ( $types as $name ) {
			$key = sanitize_key( (string) $name );
			if ( '' === $key || 'attachment' === $key ) {
				continue;
			}
			$names[] = $key;
		}

		$names = array_values( array_unique( $names ) );
		return array() === $names ? array( 'post', 'page' ) : $names;
	}
}
