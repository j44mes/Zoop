<?php
/**
 * Forces one localhost HTTP connection onto a literal loopback address.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Content;

/**
 * The pin is armed for a single request and removed in a finally block.
 *
 * Curl options are applied again on requests-curl.before_send, which WordPress
 * runs after http_api_curl and after the Requests proxy hook. The callback has
 * to be the last one or the request is refused before connect. The connected
 * address is read from the earliest after-request callback so an earlier hook
 * cannot replace it.
 */
final class LoopbackPin {
	/**
	 * Whether a transport hook rewrote the destination.
	 *
	 * @var bool
	 */
	private bool $pinned = false;

	/**
	 * Whether the connected address was not loopback, or the evidence was not trustworthy.
	 *
	 * @var bool
	 */
	private bool $contaminated = false;

	/**
	 * Whether the cURL transport handled this request.
	 *
	 * @var bool
	 */
	private bool $curlUsed = false;

	/**
	 * Whether the final cURL hook applied the pin.
	 *
	 * @var bool
	 */
	private bool $curlSealed = false;

	/**
	 * Whether cURL reported a loopback primary address.
	 *
	 * @var bool
	 */
	private bool $curlVerified = false;

	/**
	 * Whether fsockopen rewrote the socket.
	 *
	 * @var bool
	 */
	private bool $socketAccepted = false;

	/**
	 * Whether fsockopen reported the literal dial address.
	 *
	 * @var bool
	 */
	private bool $socketVerified = false;

	/**
	 * Whether this pin was armed for a real request.
	 *
	 * @var bool
	 */
	private bool $strict = false;

	/**
	 * Whether the request hooks are currently registered.
	 *
	 * @var bool
	 */
	private bool $armed = false;

	public function __construct(
		private readonly string $url,
		private readonly string $address
	) {}

	/**
	 * Register the pin for the next HTTP request.
	 *
	 * @throws FetchBlockedException When the hooks cannot be installed.
	 */
	public function arm(): void {
		if ( ! function_exists( 'add_action' ) ) {
			throw new FetchBlockedException( 'Fetch target resolves to a blocked address.' );
		}

		add_action( 'http_api_curl', array( $this, 'pinCurl' ), PHP_INT_MAX, 3 );
		add_action( 'requests-curl.before_send', array( $this, 'sealCurl' ), PHP_INT_MAX, 1 );
		add_action( 'requests-curl.after_request', array( $this, 'verifyCurl' ), PHP_INT_MIN, 2 );
		add_action( 'requests-fsockopen.remote_socket', array( $this, 'pinSocket' ), PHP_INT_MAX, 1 );
		add_action( 'requests-fsockopen.after_request', array( $this, 'verifySocket' ), PHP_INT_MIN, 2 );
		$this->armed  = true;
		$this->strict = true;
	}

	/**
	 * Remove the hooks registered for this request.
	 */
	public function disarm(): void {
		if ( ! $this->armed || ! function_exists( 'remove_action' ) ) {
			$this->armed = false;
			return;
		}

		remove_action( 'http_api_curl', array( $this, 'pinCurl' ), PHP_INT_MAX );
		remove_action( 'requests-curl.before_send', array( $this, 'sealCurl' ), PHP_INT_MAX );
		remove_action( 'requests-curl.after_request', array( $this, 'verifyCurl' ), PHP_INT_MIN );
		remove_action( 'requests-fsockopen.remote_socket', array( $this, 'pinSocket' ), PHP_INT_MAX );
		remove_action( 'requests-fsockopen.after_request', array( $this, 'verifySocket' ), PHP_INT_MIN );
		$this->armed = false;
	}

	/**
	 * Pin one cURL handle to the literal loopback address.
	 *
	 * @param mixed                $handle  cURL handle.
	 * @param array<string, mixed> $request WordPress request arguments. Unused.
	 * @param string               $url     Request URL.
	 * @throws FetchBlockedException When a required cURL option cannot be set.
	 */
	public function pinCurl( mixed &$handle, array $request = array(), string $url = '' ): void {
		unset( $request );
		if ( ! $this->matches( '' !== $url ? $url : $this->url ) ) {
			throw new FetchBlockedException( 'Fetch target resolves to a blocked address.' );
		}

		if ( ! function_exists( 'curl_setopt' ) ) {
			throw new FetchBlockedException( 'Fetch target resolves to a blocked address.' );
		}

		$dial = $this->dialHost();
		try {
			$resolve = 'localhost:' . $this->port() . ':' . $dial;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Pins this cURL handle to loopback before WordPress connects.
			$ok = curl_setopt( $handle, CURLOPT_RESOLVE, array( $resolve ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Clears a proxy so the pin cannot be redirected.
			$ok = true === $ok && true === curl_setopt( $handle, CURLOPT_PROXY, '' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Clears a proxy so the pin cannot be redirected.
			$ok = $ok && true === curl_setopt( $handle, CURLOPT_NOPROXY, '*' );
			if ( defined( 'CURLOPT_CONNECT_TO' ) ) {
				$connect = 'localhost:' . $this->port() . ':' . $dial . ':' . $this->port();
				// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Pins this cURL handle to loopback before WordPress connects.
				$ok = $ok && true === curl_setopt( $handle, CURLOPT_CONNECT_TO, array( $connect ) );
			}
		} catch ( \Throwable $exception ) {
			unset( $exception );
			$ok = false;
		}

		if ( true !== $ok ) {
			throw new FetchBlockedException( 'Fetch target resolves to a blocked address.' );
		}

		$this->curlUsed = true;
		$this->pinned   = true;
	}

	/**
	 * Reapply the cURL pin after every other before-send callback.
	 *
	 * @param mixed $handle cURL handle.
	 * @throws FetchBlockedException When this callback is not last or an option cannot be set.
	 */
	public function sealCurl( mixed &$handle ): void {
		$this->assertLast( 'requests-curl.before_send', 'sealCurl' );
		$this->pinCurl( $handle );
		$this->curlSealed = true;
	}

	/**
	 * Replace the fsockopen destination with the literal loopback address.
	 *
	 * @param mixed $remote_socket Socket string WordPress is about to open.
	 * @throws FetchBlockedException When the socket cannot be forced onto loopback.
	 */
	public function pinSocket( mixed &$remote_socket ): void {
		if ( $this->strict ) {
			$this->assertLast( 'requests-fsockopen.remote_socket', 'pinSocket' );
		}

		$socket = (string) $remote_socket;
		$prefix = '';
		if ( str_starts_with( $socket, 'ssl://' ) ) {
			$prefix = 'ssl';
		} elseif ( str_starts_with( $socket, 'tcp://' ) ) {
			$prefix = 'tcp';
		}

		if ( '' === $prefix ) {
			throw new FetchBlockedException( 'Fetch target resolves to a blocked address.' );
		}

		$remote_socket        = $prefix . '://' . $this->dialHost() . ':' . $this->port();
		$this->pinned         = true;
		$this->socketAccepted = true;
	}

	/**
	 * Record whether cURL actually connected to loopback.
	 *
	 * @param mixed $headers Response headers. Unused.
	 * @param mixed $info    cURL information, including primary_ip.
	 */
	public function verifyCurl( mixed $headers = null, mixed $info = null ): void {
		unset( $headers );
		if ( $this->strict && ! $this->isFirst( 'requests-curl.after_request', 'verifyCurl' ) ) {
			$this->contaminated = true;
			return;
		}

		$ip = '';
		if ( is_array( $info ) && isset( $info['primary_ip'] ) ) {
			$ip = (string) $info['primary_ip'];
		}

		if ( $this->loopbackIp( $ip ) ) {
			$this->curlVerified = true;
			return;
		}

		$this->contaminated = true;
	}

	/**
	 * Record whether fsockopen opened the literal dial address.
	 *
	 * @param mixed $headers Response headers. Unused.
	 * @param mixed $info    Stream metadata, including the connected URI.
	 */
	public function verifySocket( mixed $headers = null, mixed $info = null ): void {
		unset( $headers );
		if ( $this->strict && ! $this->isFirst( 'requests-fsockopen.after_request', 'verifySocket' ) ) {
			$this->contaminated = true;
			return;
		}

		$uri = '';
		if ( is_array( $info ) && isset( $info['uri'] ) ) {
			$uri = (string) $info['uri'];
		}

		if ( $this->pinnedUri( $uri ) ) {
			$this->socketVerified = true;
			return;
		}

		$this->contaminated = true;
	}

	/**
	 * Whether a failed dial stayed on the literal loopback address.
	 */
	public function dialIsGuaranteed(): bool {
		if ( ! $this->pinned || $this->contaminated ) {
			return false;
		}

		if ( $this->strict && $this->curlUsed && ! $this->curlSealed ) {
			return false;
		}

		if ( $this->strict && ! $this->curlUsed && ! $this->socketAccepted ) {
			return false;
		}

		return true;
	}

	/**
	 * Reject a response unless the pin ran and the connected address stayed on loopback.
	 *
	 * @throws FetchBlockedException When the pin did not run or the connected address left loopback.
	 */
	public function assertSafe(): void {
		if ( ! $this->dialIsGuaranteed() ) {
			throw new FetchBlockedException( 'Fetch target resolves to a blocked address.' );
		}

		if ( $this->curlUsed && ! $this->curlVerified ) {
			throw new FetchBlockedException( 'Fetch target resolves to a blocked address.' );
		}

		if ( $this->strict && $this->socketAccepted && ! $this->socketVerified ) {
			throw new FetchBlockedException( 'Fetch target resolves to a blocked address.' );
		}
	}

	public function pinned(): bool {
		return $this->pinned;
	}

	public function port(): int {
		$origin = $this->origin( $this->url );
		return null === $origin ? 0 : $origin['port'];
	}

	/**
	 * Refuse to return when another callback is registered after this one.
	 *
	 * @throws FetchBlockedException When this method is not the last callback on the hook.
	 */
	private function assertLast( string $hook, string $method ): void {
		$callbacks = $this->orderedCallbacks( $hook );
		$last      = array() === $callbacks ? null : $callbacks[ array_key_last( $callbacks ) ];
		if ( ! $this->callbackIs( $last, $method ) ) {
			throw new FetchBlockedException( 'Fetch target resolves to a blocked address.' );
		}
	}

	/**
	 * Whether this method is the first callback that will see transport evidence.
	 */
	private function isFirst( string $hook, string $method ): bool {
		$callbacks = $this->orderedCallbacks( $hook );
		return array() !== $callbacks && $this->callbackIs( $callbacks[0], $method );
	}

	/**
	 * Callbacks in execution order.
	 *
	 * @return array<int, mixed>
	 */
	private function orderedCallbacks( string $hook ): array {
		$groups = $this->callbackGroups( $hook );
		if ( array() === $groups ) {
			return array();
		}

		ksort( $groups, SORT_NUMERIC );
		$ordered = array();
		foreach ( $groups as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			foreach ( $group as $callback ) {
				$ordered[] = $callback;
			}
		}

		return $ordered;
	}

	/**
	 * Hook callbacks from WordPress, or from the unit-test registry.
	 *
	 * @return array<int|string, mixed>
	 */
	private function callbackGroups( string $hook ): array {
		if ( isset( $GLOBALS['wp_filter'][ $hook ] ) && $GLOBALS['wp_filter'][ $hook ] instanceof \WP_Hook ) {
			$callbacks = $GLOBALS['wp_filter'][ $hook ]->callbacks;
			return is_array( $callbacks ) ? $callbacks : array();
		}

		$groups = $GLOBALS['seostudio_hooks'][ $hook ] ?? null;
		return is_array( $groups ) ? $groups : array();
	}

	/**
	 * Whether a registered callback points at this pin method.
	 */
	private function callbackIs( mixed $registered, string $method ): bool {
		$function = $registered;
		if ( is_array( $registered ) && isset( $registered['function'] ) ) {
			$function = $registered['function'];
		}

		return is_array( $function )
			&& isset( $function[0], $function[1] )
			&& $function[0] === $this
			&& $function[1] === $method;
	}

	private function matches( string $url ): bool {
		$origin = $this->origin( $url );
		return null !== $origin && 'localhost' === $origin['host'] && $origin['port'] === $this->port();
	}

	private function dialHost(): string {
		return '::1' === $this->address ? '[::1]' : '127.0.0.1';
	}

	private function loopbackIp( string $ip ): bool {
		return in_array( strtolower( trim( $ip, '[]' ) ), array( '127.0.0.1', '::1' ), true );
	}

	/**
	 * Whether stream metadata names the literal address this pin dialed.
	 */
	private function pinnedUri( string $uri ): bool {
		$uri = strtolower( trim( $uri ) );
		foreach ( array( 'tcp://', 'ssl://' ) as $prefix ) {
			$expected = $prefix . strtolower( $this->dialHost() ) . ':' . $this->port();
			if ( $uri === $expected ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Scheme, host, and port for the pinned URL.
	 *
	 * @return array{scheme: string, host: string, port: int}|null
	 */
	private function origin( string $url ): ?array {
		$parts = Urls::parts( trim( $url ) );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( rtrim( (string) $parts['host'], '.' ) );
		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );

		return array(
			'scheme' => $scheme,
			'host'   => $host,
			'port'   => $port,
		);
	}
}
