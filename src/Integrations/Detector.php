<?php
/**
 * Detects other SEO plugins by their version constants.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Integrations;

/**
 * Constants are set before output, so detection does not read options.
 */
final class Detector {
	/**
	 * Plugins in the order the resolver consults them.
	 *
	 * @return array<int, array{slug: string, name: string, constant: string}>
	 */
	public function catalogue(): array {
		return array(
			array(
				'slug'     => 'yoast',
				'name'     => 'Yoast SEO',
				'constant' => 'WPSEO_VERSION',
			),
			array(
				'slug'     => 'rank_math',
				'name'     => 'Rank Math',
				'constant' => 'RANK_MATH_VERSION',
			),
			array(
				'slug'     => 'seopress',
				'name'     => 'SEOPress',
				'constant' => 'SEOPRESS_VERSION',
			),
			array(
				'slug'     => 'aioseo',
				'name'     => 'All in One SEO',
				'constant' => 'AIOSEO_VERSION',
			),
			array(
				'slug'     => 'tsf',
				'name'     => 'The SEO Framework',
				'constant' => 'THE_SEO_FRAMEWORK_VERSION',
			),
		);
	}

	/**
	 * Active plugins.
	 *
	 * @return array<int, array{slug: string, name: string, version: string}>
	 */
	public function active(): array {
		$found = array();
		foreach ( $this->catalogue() as $plugin ) {
			$version = $this->version( $plugin['constant'] );
			if ( '' === $version ) {
				continue;
			}
			$found[] = array(
				'slug'    => $plugin['slug'],
				'name'    => $plugin['name'],
				'version' => $version,
			);
		}

		return $found;
	}

	/**
	 * The first active plugin, or null when SEO Studio is the only one.
	 *
	 * @return array{slug: string, name: string, version: string}|null
	 */
	public function first(): ?array {
		$active = $this->active();
		return $active[0] ?? null;
	}

	private function version( string $constant ): string {
		if ( ! defined( $constant ) ) {
			return '';
		}

		$value = constant( $constant );
		return is_scalar( $value ) ? (string) $value : '';
	}
}
