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
	 * Merge site defaults with a per-field post override.
	 *
	 * A null or empty field inherits the site default. False is an override.
	 * Snippet and image-preview values are emitted when either level sets them.
	 *
	 * @param array<string, bool|string> $robots
	 * @return array<string, bool|string>
	 */
	public function filter( array $robots ): array {
		$default = Settings::all()['default_robots'] ?? array();
		$default = is_array( $default ) ? $default : array();
		$stored  = array();
		if ( is_singular() ) {
			$meta   = get_post_meta( (int) get_queried_object_id(), '_seostudio_robots', true );
			$stored = is_array( $meta ) ? $meta : array();
		}

		$index   = $this->value( $stored, $default, 'index', true );
		$follow  = $this->value( $stored, $default, 'follow', true );
		$snippet = $this->value( $stored, $default, 'max_snippet', null );
		$preview = $this->value( $stored, $default, 'max_image_preview', null );

		if ( $this->disabled( $index ) ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		}
		if ( $this->disabled( $follow ) ) {
			$robots['nofollow'] = true;
			unset( $robots['follow'] );
		}
		if ( is_numeric( $snippet ) ) {
			$robots['max-snippet'] = (string) (int) $snippet;
		}
		if ( is_string( $preview ) && in_array( $preview, array( 'none', 'standard', 'large' ), true ) ) {
			$robots['max-image-preview'] = $preview;
		}

		return $robots;
	}

	/**
	 * The post value when it is set, otherwise the site default.
	 *
	 * @param array<string, mixed> $stored
	 * @param array<string, mixed> $defaults
	 */
	private function value( array $stored, array $defaults, string $key, mixed $fallback ): mixed {
		if ( $this->present( $stored, $key ) ) {
			return $stored[ $key ];
		}
		if ( $this->present( $defaults, $key ) ) {
			return $defaults[ $key ];
		}

		return $fallback;
	}

	/**
	 * Whether this field is an explicit override. Null and an empty string inherit.
	 *
	 * @param array<string, mixed> $source
	 */
	private function present( array $source, string $key ): bool {
		if ( ! array_key_exists( $key, $source ) ) {
			return false;
		}

		$value = $source[ $key ];
		return null !== $value && '' !== $value;
	}

	/**
	 * Index and follow store false, 0, or "0" when the directive is off.
	 */
	private function disabled( mixed $value ): bool {
		return false === $value || 0 === $value || '0' === $value;
	}
}
