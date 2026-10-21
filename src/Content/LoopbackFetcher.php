<?php
/**
 * Fetches the public HTML of a page on this site.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Content;

/**
 * Full-page fetch used for code checks and Verify live.
 */
final class LoopbackFetcher {
	public const MAX_BYTES = 2097152;

	/**
	 * Optional transport replaces wp_remote_get in tests.
	 *
	 * @param callable(string, array<string, mixed>): array{code: int, location: string, body: string}|null $transport
	 */
	public function __construct(
		private readonly FetchPolicy $policy = new FetchPolicy(),
		private readonly Extractor $extractor = new Extractor(),
		private readonly mixed $transport = null
	) {}

	/**
	 * Download a same-host page and extract it.
	 *
	 * @param array<string, mixed> $context
	 */
	public function fetch( string $url, array $context = array() ): PageModel {
		$home                   = function_exists( 'home_url' ) ? (string) home_url( '/' ) : (string) ( $context['home_url'] ?? '' );
		$response               = $this->download( $url, $home, 0 );
		$html                   = $response['body'];
		$context['http_robots'] = $response['robots'];
		$context['render_mode'] = 'live';
		$context['url']         = $url;
		if ( ! array_key_exists( 'is_https', $context ) ) {
			$context['is_https'] = str_starts_with( strtolower( $url ), 'https://' );
		}

		return $this->extractor->extract( $html, $context );
	}

	/**
	 * Follow allowed redirects and retain response directives.
	 *
	 * @return array{body: string, robots: string}
	 * @throws FetchBlockedException When the status or response size is invalid.
	 */
	private function download( string $url, string $home, int $hops ): array {
		$this->policy->assertAllowed( $url, $home );
		$response = $this->perform( $url );

		if ( $response['code'] >= 300 && $response['code'] < 400 ) {
			$next = $this->policy->nextHop( $url, $response['location'], $home, $hops + 1 );
			return $this->download( $next, $home, $hops + 1 );
		}

		if ( 200 !== $response['code'] ) {
			throw new FetchBlockedException( 'Fetch returned a non-200 status.' );
		}

		if ( strlen( $response['body'] ) > self::MAX_BYTES ) {
			throw new FetchBlockedException( 'Response exceeded 2 MB.', 'oversized' );
		}

		return array(
			'body'   => $response['body'],
			'robots' => $response['robots'],
		);
	}

	/**
	 * Perform one HTTP request without following redirects.
	 *
	 * @return array{code: int, location: string, body: string, robots: string}
	 * @throws FetchBlockedException When the transport fails.
	 */
	private function perform( string $url ): array {
		$args = array(
			'timeout'             => 20,
			'redirection'         => 0,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => self::MAX_BYTES + 1,
			'user-agent'          => 'Zoop/0.1 (+https://example.test)',
		);

		if ( defined( 'SEOSTUDIO_FETCH_USER' ) && defined( 'SEOSTUDIO_FETCH_PASS' ) ) {
			$args['headers'] = array(
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials, not obfuscation.
				'Authorization' => 'Basic ' . base64_encode( SEOSTUDIO_FETCH_USER . ':' . SEOSTUDIO_FETCH_PASS ),
			);
		}

		$transport = $this->transport;
		if ( is_callable( $transport ) ) {
			$result = $transport( $url, $args );
			if ( ! is_array( $result ) ) {
				throw new FetchBlockedException( 'Fetch failed.' );
			}

			return array(
				'code'     => (int) ( $result['code'] ?? 0 ),
				'location' => (string) ( $result['location'] ?? '' ),
				'body'     => (string) ( $result['body'] ?? '' ),
				'robots'   => (string) ( $result['robots'] ?? '' ),
			);
		}

		if ( $this->isLocalhost( $url ) ) {
			return $this->pinnedRequest( $url, $args );
		}

		return $this->remote( $url, $args );
	}

	/**
	 * Download localhost only after the connection is pinned to loopback.
	 *
	 * @param array<string, mixed> $args
	 * @return array{code: int, location: string, body: string, robots: string}
	 * @throws FetchBlockedException When the connection cannot be pinned to loopback.
	 */
	private function pinnedRequest( string $url, array $args ): array {
		if ( $this->proxyWouldCarry( $url ) ) {
			throw new FetchBlockedException( 'Fetch target resolves to a blocked address.' );
		}

		$last = null;
		foreach ( array( '127.0.0.1', '::1' ) as $address ) {
			$pin = new LoopbackPin( $url, $address );
			$pin->arm();
			try {
				$response = wp_remote_get( $url, $args );
				if ( is_wp_error( $response ) ) {
					if ( ! $pin->dialIsGuaranteed() ) {
						throw new FetchBlockedException( 'Fetch target resolves to a blocked address.' );
					}
					$last = $response;
					continue;
				}

				$pin->assertSafe();
				return $this->remoteResult( $response );
			} finally {
				$pin->disarm();
			}
		}

		unset( $last );
		throw new FetchBlockedException( 'Fetch failed.' );
	}

	/**
	 * Download a public host with the normal WordPress transport.
	 *
	 * @param array<string, mixed> $args
	 * @return array{code: int, location: string, body: string, robots: string}
	 * @throws FetchBlockedException When the transport fails.
	 */
	private function remote( string $url, array $args ): array {
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new FetchBlockedException( 'Fetch failed.' );
		}

		return $this->remoteResult( $response );
	}

	/**
	 * Normalise a WordPress HTTP response.
	 *
	 * @param array<string, mixed> $response
	 * @return array{code: int, location: string, body: string, robots: string}
	 */
	private function remoteResult( array $response ): array {
		return array(
			'code'     => (int) wp_remote_retrieve_response_code( $response ),
			'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
			'body'     => (string) wp_remote_retrieve_body( $response ),
			'robots'   => implode( ', ', (array) wp_remote_retrieve_header( $response, 'x-robots-tag' ) ),
		);
	}

	/**
	 * Whether the URL host is the literal name localhost.
	 */
	private function isLocalhost( string $url ): bool {
		$parts = Urls::parts( trim( $url ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}

		return 'localhost' === strtolower( rtrim( (string) $parts['host'], '.' ) );
	}

	private function proxyWouldCarry( string $url ): bool {
		if ( ! class_exists( 'WP_HTTP_Proxy' ) ) {
			return false;
		}

		$proxy = new \WP_HTTP_Proxy();
		return $proxy->is_enabled() && $proxy->send_through_proxy( $url );
	}
}
