<?php
/**
 * Same-host allow-list for full-page fetches.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Content;

/**
 * Rejects any URL that is not the site's own public host.
 */
final class FetchPolicy {
	/**
	 * __construct.
	 *
	 * @param callable(string): array<int, string>|null $resolver
	 */
	public function __construct( private readonly mixed $resolver = null ) {}

	public function assertAllowed( string $url, string $homeUrl ): string {
		$target = $this->parts( $url );
		$home   = $this->parts( $homeUrl );

		if ( $target['scheme'] !== $home['scheme'] || $target['host'] !== $home['host'] || $target['port'] !== $home['port'] ) {
			throw new FetchBlockedException( 'Fetch target is not the site host.', 'unsafe_target' );
		}

		if ( ( new LocalObservation() )->permits( $url, $homeUrl ) ) {
			return $url;
		}

		if ( $this->isBlockedHost( $target['host'] ) || $this->resolvesToBlockedAddress( $target['host'] ) ) {
			throw new FetchBlockedException( 'Fetch target resolves to a blocked address.', 'unsafe_target' );
		}

		return $url;
	}

	public function nextHop( string $currentUrl, string $location, string $homeUrl, int $hops ): string {
		if ( $hops > 2 ) {
			throw new FetchBlockedException( 'Too many redirects.', 'unsafe_target' );
		}

		$next = $this->absolute( $currentUrl, $location );
		return $this->assertAllowed( $next, $homeUrl );
	}

	private function absolute( string $currentUrl, string $location ): string {
		if ( str_starts_with( $location, 'http://' ) || str_starts_with( $location, 'https://' ) ) {
			return $location;
		}

		$parts = Urls::parts( $currentUrl );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			throw new FetchBlockedException( 'Cannot resolve redirect.', 'unsafe_target' );
		}

		$port = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		if ( str_starts_with( $location, '/' ) ) {
			return $parts['scheme'] . '://' . $parts['host'] . $port . $location;
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '/';
		$dir  = rtrim( str_replace( '\\', '/', dirname( $path ) ), '/' );
		return $parts['scheme'] . '://' . $parts['host'] . $port . $dir . '/' . $location;
	}

	/**
	 * Split an absolute URL into scheme, host, and port.
	 *
	 * @return array{scheme: string, host: string, port: int}
	 * @throws FetchBlockedException When the URL is not an allowed absolute HTTP URL.
	 */
	private function parts( string $url ): array {
		$parts = Urls::parts( trim( $url ) );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			throw new FetchBlockedException( 'URL is not absolute.', 'unsafe_target' );
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			throw new FetchBlockedException( 'URL credentials are not allowed.', 'unsafe_target' );
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			throw new FetchBlockedException( 'Only HTTP URLs can be fetched.', 'unsafe_target' );
		}

		$host = strtolower( rtrim( (string) $parts['host'], '.' ) );
		if ( '' === $host || str_contains( $host, '%' ) ) {
			throw new FetchBlockedException( 'Host is not allowed.', 'unsafe_target' );
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

		return array(
			'scheme' => $scheme,
			'host'   => $host,
			'port'   => $port,
		);
	}

	private function isBlockedHost( string $host ): bool {
		if ( in_array( $host, array( 'localhost', 'localhost.localdomain' ), true ) ) {
			return true;
		}

		if ( str_ends_with( $host, '.local' ) || str_ends_with( $host, '.internal' ) ) {
			return true;
		}

		return false !== filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP );
	}

	private function resolvesToBlockedAddress( string $host ): bool {
		if ( $this->isBlockedAddress( $host ) ) {
			return true;
		}

		$resolver = $this->resolver;
		$records  = is_callable( $resolver ) ? $resolver( $host ) : $this->resolve( $host );
		if ( ! is_array( $records ) ) {
			return true;
		}

		foreach ( $records as $address ) {
			if ( ! is_string( $address ) || $this->isBlockedAddress( $address ) ) {
				return true;
			}
		}

		return array() === $records;
	}

	/**
	 * Resolve a host to public IP addresses.
	 *
	 * @return string[]
	 */
	private function resolve( string $host ): array {
		$records = dns_get_record( $host, DNS_A + DNS_AAAA );
		if ( ! is_array( $records ) ) {
			return array();
		}

		$addresses = array();
		foreach ( $records as $record ) {
			if ( isset( $record['ip'] ) ) {
				$addresses[] = (string) $record['ip'];
			}
			if ( isset( $record['ipv6'] ) ) {
				$addresses[] = (string) $record['ipv6'];
			}
		}

		return $addresses;
	}

	private function isBlockedAddress( string $host ): bool {
		$ip = trim( $host, '[]' );
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		return false === filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}
}
