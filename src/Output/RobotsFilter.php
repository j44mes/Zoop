<?php
/**
 * Robots directives for Owner mode.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Output;

use SEOStudio\Core\Settings;

/**
 * Per-post robots override the site default. Null inherits the default.
 */
final class RobotsFilter {
	/**
	 * Documented behaviour for this method.
	 *
	 * @param array<string, bool|string> $robots
	 * @return array<string, bool|string>
	 */
	public function filter( array $robots ): array {
		if ( ! is_singular() ) {
			return $robots;
		}

		$stored  = get_post_meta( (int) get_queried_object_id(), '_seostudio_robots', true );
		$default = Settings::all()['default_robots'] ?? array();
		$chosen  = is_array( $stored ) && array() !== $stored ? $stored : ( is_array( $default ) ? $default : array() );
		$index   = array_key_exists( 'index', $chosen ) ? $chosen['index'] : true;
		$follow  = array_key_exists( 'follow', $chosen ) ? $chosen['follow'] : true;

		if ( false === $index || 0 === $index || '0' === $index ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}
		if ( false === $follow || 0 === $follow || '0' === $follow ) {
			$robots['nofollow'] = true;
			unset( $robots['follow'] );
		}

		return $robots;
	}
}
