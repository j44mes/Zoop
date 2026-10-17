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
		$html                   = $this->download( $url, $home, 0 );
		$context['render_mode'] = 'live';
		$context['url']         = $url;
		if ( ! array_key_exists( 'is_https', $context ) ) {
			$context['is_https'] = str_starts_with( strtolower( $url ), 'https://' );
		}

		return $this->extractor->extract( $html, $context );
	}

	private function download( string $url, string $home, int $hops ): string {
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

		return $response['body'];
	}

	/**
	 * Perform one HTTP request without following redirects.
	 *
	 * @return array{code: int, location: string, body: string}
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
			);
		}

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new FetchBlockedException( 'Fetch failed.' );
		}

		return array(
			'code'     => (int) wp_remote_retrieve_response_code( $response ),
			'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
			'body'     => (string) wp_remote_retrieve_body( $response ),
		);
	}
}
