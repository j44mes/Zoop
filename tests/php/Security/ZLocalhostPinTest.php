<?php
/**
 * Proves the localhost observation switch through the live analysis path.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Security;

use SEOStudio\Content\FetchPolicy;
use SEOStudio\Content\LocalObservation;
use SEOStudio\Content\LoopbackFetcher;
use SEOStudio\Core\Plugin;
use SEOStudio\Rest\PagesController;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The constant stays undefined for the blocked case. The allowed case defines it last.
 */
final class ZLocalhostPinTest extends WP_UnitTestCase {
	/**
	 * @var resource|null
	 */
	private $serverProcess = null;

	private string $serverScript = '';

	private string $serverFlag = '';

	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$this->stopServer();
		putenv( 'WP_ENVIRONMENT_TYPE' );
		parent::tear_down();
	}

	public function test_localhost_live_analysis_is_blocked_when_observation_is_off(): void {
		$this->assertFalse( ( new LocalObservation() )->configured() );
		$this->useHome( 'http://localhost' );
		putenv( 'WP_ENVIRONMENT_TYPE=local' );

		$called = false;
		$result = $this->analyse(
			'http://localhost/secret/',
			new LoopbackFetcher(
				policy: new FetchPolicy( static fn (): array => array( '127.0.0.1' ) ),
				transport: static function () use ( &$called ): array {
					$called = true;
					return array(
						'code'     => 200,
						'location' => '',
						'body'     => '<html><head><title>Leaked</title></head><body><p>Leaked</p></body></html>',
						'robots'   => '',
					);
				}
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'seostudio_fetch_blocked', $result->get_error_code() );
		$this->assertStringContainsString( 'blocked address', $result->get_error_message() );
		$this->assertFalse( $called );
	}

	public function test_localhost_live_analysis_is_pinned_when_observation_is_on(): void {
		if ( ! defined( 'SEOSTUDIO_LOCAL_OBSERVE' ) ) {
			define( 'SEOSTUDIO_LOCAL_OBSERVE', true );
		}
		$this->assertTrue( ( new LocalObservation() )->configured() );
		$this->assertContains( wp_get_environment_type(), array( 'local', 'development' ) );

		$port = $this->reservePort();
		$home = 'http://localhost:' . $port;
		$this->useHome( $home );
		$this->startServer( $port );
		$marker = 'Pinned loopback title';
		add_action(
			'http_api_curl',
			static function ( &$handle ) use ( $port ): void {
				if ( function_exists( 'curl_setopt' ) ) {
					curl_setopt( $handle, CURLOPT_RESOLVE, array( 'localhost:' . $port . ':203.0.113.5' ) );
				}
			},
			10,
			3
		);
		add_action(
			'requests-curl.before_send',
			static function ( &$handle ) use ( $port ): void {
				if ( function_exists( 'curl_setopt' ) ) {
					curl_setopt( $handle, CURLOPT_RESOLVE, array( 'localhost:' . $port . ':203.0.113.5' ) );
				}
			},
			10,
			1
		);
		add_action(
			'requests-fsockopen.remote_socket',
			static function ( &$socket ) use ( $port ): void {
				$socket = 'tcp://203.0.113.5:' . $port;
			},
			10,
			1
		);

		$result = $this->analyse( $home . '/observed/', new LoopbackFetcher() );

		$this->assertNotInstanceOf( WP_Error::class, $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$data = $result->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'observed', $data['page_model']['title_evidence'] ?? '' );
		$this->assertStringContainsString( $marker, (string) ( $data['page_model']['title_tag'] ?? '' ) );
		$this->assertFileExists( $this->serverFlag );
		$this->assertStringContainsString( 'GET /observed/', (string) file_get_contents( $this->serverFlag ) );
	}

	private function useHome( string $home ): void {
		update_option( 'home', $home );
		update_option( 'siteurl', $home );
		$filter = static function () use ( $home ): string {
			return $home;
		};
		add_filter( 'pre_option_home', $filter );
		add_filter( 'pre_option_siteurl', $filter );
	}

	/**
	 * @return WP_Error|\WP_REST_Response
	 */
	private function analyse( string $url, LoopbackFetcher $fetcher ): WP_Error|\WP_REST_Response {
		$post = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Observed page',
			)
		);
		add_filter(
			'post_link',
			static function () use ( $url ): string {
				return $url;
			}
		);
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'object_type', 'post' );
		$request->set_param( 'object_id', $post );
		$request->set_param( 'mode', 'live' );
		$request->set_param( 'keyphrase', 'observed page' );

		return ( new PagesController( fetcher: $fetcher ) )->analyseObject( $request );
	}

	private function reservePort(): int {
		$socket = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
		$this->assertNotFalse( $socket, $errstr );
		$name = stream_socket_get_name( $socket, false );
		fclose( $socket );
		$this->assertIsString( $name );

		return (int) substr( $name, (int) strrpos( $name, ':' ) + 1 );
	}

	private function startServer( int $port ): void {
		$this->serverScript = tempnam( sys_get_temp_dir(), 'zoop-pin' );
		$this->serverFlag   = $this->serverScript . '.hit';
		$this->assertNotFalse( $this->serverScript );
		file_put_contents(
			$this->serverScript,
			<<<'PHP'
<?php
$port = (int) $argv[1];
$flag = $argv[2];
$server = stream_socket_server( 'tcp://127.0.0.1:' . $port, $errno, $errstr );
if ( ! $server ) {
	fwrite( STDERR, $errstr );
	exit( 1 );
}
$socket = stream_socket_accept( $server, 20 );
if ( ! $socket ) {
	exit( 2 );
}
stream_set_timeout( $socket, 5 );
$request = '';
while ( ! str_contains( $request, "\r\n\r\n" ) && strlen( $request ) < 65536 ) {
	$chunk = fread( $socket, 2048 );
	if ( ! is_string( $chunk ) || '' === $chunk ) {
		break;
	}
	$request .= $chunk;
}
file_put_contents( $flag, $request );
$body = '<html><head><title>Pinned loopback title</title></head><body><main><p>Body</p></main></body></html>';
fwrite( $socket, "HTTP/1.0 200 OK\r\nContent-Type: text/html\r\nContent-Length: " . strlen( $body ) . "\r\nConnection: close\r\n\r\n" . $body );
fclose( $socket );
fclose( $server );
PHP
		);
		$descriptor = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'file', $this->serverScript . '.out', 'w' ),
			2 => array( 'file', $this->serverScript . '.err', 'w' ),
		);
		$pipes               = array();
		$this->serverProcess = proc_open(
			array( PHP_BINARY, $this->serverScript, (string) $port, $this->serverFlag ),
			$descriptor,
			$pipes
		);
		$this->assertIsResource( $this->serverProcess );
		usleep( 150000 );
	}

	private function stopServer(): void {
		if ( is_resource( $this->serverProcess ) ) {
			proc_terminate( $this->serverProcess );
			proc_close( $this->serverProcess );
			$this->serverProcess = null;
		}
		foreach ( array( $this->serverScript, $this->serverFlag, $this->serverScript . '.out', $this->serverScript . '.err' ) as $path ) {
			if ( '' !== $path && is_file( $path ) ) {
				unlink( $path );
			}
		}
	}
}
