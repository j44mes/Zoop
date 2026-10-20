<?php
/**
 * Decides whether a fetch may use the literal localhost exception.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Content;

/**
 * Default off. Production and staging cannot enable it.
 */
final class LocalObservation {
	/**
	 * Whether this URL may skip the public-address check.
	 */
	public function permits( string $url, string $filteredHome ): bool {
		$target   = $this->origin( $url );
		$filtered = $this->origin( $filteredHome );
		if ( null === $target || null === $filtered ) {
			return false;
		}

		return $this->decision(
			$target['host'],
			$target['scheme'],
			$target['port'],
			$filtered['scheme'],
			$filtered['host'],
			$filtered['port'],
			$this->rawOrigin(),
			$this->configured(),
			$this->environment()
		);
	}

	/**
	 * Pure allow decision. A null raw origin means the stored home could not be read.
	 *
	 * @param array{scheme: string, host: string, port: int}|null $rawHome
	 */
	public function decision(
		string $host,
		string $scheme,
		int $port,
		string $filteredScheme,
		string $filteredHost,
		int $filteredPort,
		?array $rawHome,
		bool $configured,
		string $environment
	): bool {
		if ( ! $configured || ! in_array( $environment, array( 'local', 'development' ), true ) ) {
			return false;
		}

		if ( 'localhost' !== $host || 'localhost' !== $filteredHost || null === $rawHome || 'localhost' !== $rawHome['host'] ) {
			return false;
		}

		if ( $scheme !== $filteredScheme || $port !== $filteredPort ) {
			return false;
		}

		return $rawHome['scheme'] === $scheme && $rawHome['port'] === $port;
	}

	public function configured(): bool {
		return defined( 'SEOSTUDIO_LOCAL_OBSERVE' ) && true === SEOSTUDIO_LOCAL_OBSERVE;
	}

	/**
	 * Read the stored home URL without request filters.
	 *
	 * @return array{scheme: string, host: string, port: int}|null
	 */
	private function rawOrigin(): ?array {
		$value = $this->storedHome();
		return is_string( $value ) ? $this->origin( $value ) : null;
	}

	/**
	 * Stored home option, or the unit-test stand-in when WordPress has no database.
	 */
	private function storedHome(): ?string {
		global $wpdb;

		if ( isset( $wpdb ) && $wpdb instanceof \wpdb ) {
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'home' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The options table name is WordPress's own property.
			return is_string( $found ) ? $found : null;
		}

		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}

		$found = get_option( 'home' );
		return is_string( $found ) ? $found : null;
	}

	private function environment(): string {
		if ( function_exists( 'wp_get_environment_type' ) ) {
			$environment = wp_get_environment_type();
			return is_string( $environment ) && '' !== $environment ? $environment : 'production';
		}

		$environment = getenv( 'WP_ENVIRONMENT_TYPE' );
		return is_string( $environment ) && '' !== $environment ? $environment : 'production';
	}

	/**
	 * Scheme, host, and port for an absolute HTTP URL.
	 *
	 * @return array{scheme: string, host: string, port: int}|null
	 */
	private function origin( string $url ): ?array {
		$parts = Urls::parts( trim( $url ) );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return null;
		}

		$host = strtolower( rtrim( (string) $parts['host'], '.' ) );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

		return array(
			'scheme' => $scheme,
			'host'   => $host,
			'port'   => $port,
		);
	}
}
