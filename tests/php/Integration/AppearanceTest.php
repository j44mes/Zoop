<?php
/**
 * Search-appearance apply, restore, and verification.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Appearance\Decision;
use SEOStudio\Appearance\Fields;
use SEOStudio\Appearance\ProposalStore;
use SEOStudio\Content\Extractor;
use SEOStudio\Content\FetchPolicy;
use SEOStudio\Content\LoopbackFetcher;
use SEOStudio\Content\Renderer;
use SEOStudio\Core\Plugin;
use SEOStudio\Core\Settings;
use SEOStudio\Output\HeadOutput;
use SEOStudio\Rest\AppearanceController;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Proposals stay unpublished until a confirmed write, and a stale confirmation is rejected.
 */
final class AppearanceTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		Plugin::init();
		do_action( 'rest_api_init' );
		update_option( 'home', 'https://example.test' );
		update_option( 'siteurl', 'https://example.test' );
		$settings         = Settings::all();
		$settings['mode'] = 'owner';
		update_option( Settings::OPTION, $settings );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_a_saved_proposal_does_not_change_the_stored_title(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Door' ) );
		update_post_meta( $post_id, '_seostudio_title', 'Stored title' );
		update_post_meta( $post_id, '_seostudio_description', 'Stored description' );

		$result = $this->request(
			'PUT',
			$post_id,
			'/appearance',
			array(
				'title'             => 'Proposal title',
				'description'       => 'Proposal description',
				'proposal_revision' => 0,
			)
		);

		$this->assertSame( 200, $result->get_status() );
		$this->assertSame( 'Stored title', get_post_meta( $post_id, '_seostudio_title', true ) );
		$this->assertSame( 'Proposal title', get_post_meta( $post_id, '_seostudio_title_proposal', true ) );
		$this->assertSame( 1, ( new ProposalStore() )->revision( $post_id ) );
		$this->assertSame( 'saved_settings', $result->get_data()['resolved']['basis'] );
	}

	public function test_apply_and_restore_replace_only_the_confirmed_values(): void {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Door' ) );
		update_post_meta( $post_id, '_seostudio_title', 'Old title' );
		update_post_meta( $post_id, '_seostudio_description', 'Old description' );
		$this->seedProposal( $post_id, 'New title', 'New description' );

		$applied = $this->request(
			'POST',
			$post_id,
			'/appearance/apply',
			array(
				'confirm'              => true,
				'title'                => 'Body title that must not be published',
				'description'          => 'Body description that must not be published',
				'proposal_revision'    => 1,
				'baseline_title'       => 'Old title',
				'baseline_description' => 'Old description',
			)
		);

		$this->assertSame( 200, $applied->get_status() );
		$this->assertSame( 'New title', get_post_meta( $post_id, '_seostudio_title', true ) );
		$this->assertSame( 'New description', get_post_meta( $post_id, '_seostudio_description', true ) );
		$this->assertNotSame( 'verified', $applied->get_data()['verification']['status'] );

		$restored = $this->request(
			'POST',
			$post_id,
			'/appearance/restore',
			array(
				'confirm'              => true,
				'baseline_title'       => 'New title',
				'baseline_description' => 'New description',
			)
		);

		$this->assertSame( 200, $restored->get_status() );
		$this->assertSame( 'Old title', get_post_meta( $post_id, '_seostudio_title', true ) );
		$this->assertSame( 'Old description', get_post_meta( $post_id, '_seostudio_description', true ) );
	}

	public function test_a_second_editor_makes_the_confirmation_stale(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_seostudio_title', 'Old title' );
		update_post_meta( $post_id, '_seostudio_description', 'Old description' );
		$this->seedProposal( $post_id, 'Proposal', 'Proposal description' );
		update_post_meta( $post_id, '_seostudio_title', 'Changed by someone else' );

		$result = $this->request(
			'POST',
			$post_id,
			'/appearance/apply',
			array(
				'confirm'              => true,
				'proposal_revision'    => 1,
				'baseline_title'       => 'Old title',
				'baseline_description' => 'Old description',
			)
		);

		$this->assertSame( 409, $result->get_status() );
		$this->assertSame( 'seostudio_stale', $result->as_error()->get_error_code() );
		$this->assertSame( 'Changed by someone else', get_post_meta( $post_id, '_seostudio_title', true ) );
	}

	public function test_restore_is_rejected_when_the_current_values_changed(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_seostudio_title', 'Old title' );
		update_post_meta( $post_id, '_seostudio_description', 'Old description' );
		$this->seedProposal( $post_id, 'New title', 'New description' );
		$this->request(
			'POST',
			$post_id,
			'/appearance/apply',
			array(
				'confirm'              => true,
				'proposal_revision'    => 1,
				'baseline_title'       => 'Old title',
				'baseline_description' => 'Old description',
			)
		);
		update_post_meta( $post_id, '_seostudio_title', 'Edited again' );

		$result = $this->request(
			'POST',
			$post_id,
			'/appearance/restore',
			array(
				'confirm'              => true,
				'baseline_title'       => 'New title',
				'baseline_description' => 'New description',
			)
		);

		$this->assertSame( 409, $result->get_status() );
		$this->assertSame( 'Edited again', get_post_meta( $post_id, '_seostudio_title', true ) );
	}

	public function test_a_static_homepage_apply_updates_the_site_settings(): void {
		$page_id = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Welcome',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );
		update_post_meta( $page_id, '_seostudio_title', 'Page title that does not control the homepage' );
		$settings                     = Settings::all();
		$settings['home_title']       = 'Home title';
		$settings['home_description'] = 'Home description';
		update_option( Settings::OPTION, $settings );
		$this->seedProposal( $page_id, 'New home title', 'New home description' );

		$applied = $this->request(
			'POST',
			$page_id,
			'/appearance/apply',
			array(
				'confirm'              => true,
				'proposal_revision'    => 1,
				'baseline_title'       => 'Home title',
				'baseline_description' => 'Home description',
			)
		);

		$this->assertSame( 200, $applied->get_status() );
		$this->assertSame( 'homepage', $applied->get_data()['baseline']['target'] );
		$this->assertSame( 'New home title', Settings::all()['home_title'] );
		$this->assertSame( 'New home description', Settings::all()['home_description'] );
		$this->assertSame( 'Page title that does not control the homepage', get_post_meta( $page_id, '_seostudio_title', true ) );

		$restored = $this->request(
			'POST',
			$page_id,
			'/appearance/restore',
			array(
				'confirm'              => true,
				'baseline_title'       => 'New home title',
				'baseline_description' => 'New home description',
			)
		);
		$this->assertSame( 200, $restored->get_status() );
		$this->assertSame( 'Home title', Settings::all()['home_title'] );
		$this->assertSame( 'Home description', Settings::all()['home_description'] );
	}

	public function test_an_editor_cannot_apply_the_homepage_settings(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_id );
		$settings                     = Settings::all();
		$settings['home_title']       = 'Home title';
		$settings['home_description'] = 'Home description';
		update_option( Settings::OPTION, $settings );
		$this->seedProposal( $page_id, 'Editor title', 'Editor description' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$shown = $this->request( 'GET', $page_id, '/appearance', array() );
		$this->assertFalse( $shown->get_data()['can_apply'] );
		$result = $this->request(
			'POST',
			$page_id,
			'/appearance/apply',
			array(
				'confirm'              => true,
				'proposal_revision'    => 1,
				'baseline_title'       => 'Home title',
				'baseline_description' => 'Home description',
			)
		);
		$this->assertSame( 403, $result->get_status() );
		$this->assertSame( 'Home title', Settings::all()['home_title'] );
	}

	public function test_a_failed_fetch_leaves_the_write_and_does_not_call_it_verified(): void {
		$post_id = $this->controlledPost( 'Saved title', '' );
		$result  = $this->controlled( $post_id, static fn (): mixed => null, 'Saved title', '' );
		$this->assertSame( 200, $result->get_status() );
		$this->assertSame( 'Saved title', get_post_meta( $post_id, '_seostudio_title', true ) );
		$this->assertSame( 'not_observed', $result->get_data()['verification']['basis'] );
		$this->assertFalse( $result->get_data()['verification']['html_matches'] );
		$this->assertStringContainsString( 'not a verified publication', $result->get_data()['verification']['note'] );
	}

	public function test_an_observed_description_is_not_ignored_when_none_was_expected(): void {
		$post_id = $this->controlledPost( 'Saved title', '' );
		$home    = static function ( string $url, string $path ): string {
			unset( $url );
			$suffix = '' === $path ? '/' : '/' . ltrim( $path, '/' );
			return 'https://example.test' . $suffix;
		};
		$link    = static function () use ( $post_id ): string {
			return 'https://example.test/?p=' . $post_id;
		};
		add_filter( 'home_url', $home, 10, 2 );
		add_filter( 'post_link', $link, 10, 0 );
		add_filter( 'page_link', $link, 10, 0 );
		add_filter( 'post_type_link', $link, 10, 0 );
		$resolver_host = '';
		$transport_url = '';
		$home_at_fetch = '';
		$result        = null;
		try {
			$generated = (string) get_permalink( $post_id );
			$result    = $this->controlled(
				$post_id,
				function ( string $url ) use ( &$transport_url, &$home_at_fetch ): array {
					$transport_url = $url;
					$home_at_fetch = (string) home_url( '/' );
					return array(
						'code'     => 200,
						'location' => '',
						'body'     => '<html><head><title>Saved title</title><meta name="description" content="Still published"></head><body><main><p>Visible page copy for the check.</p></main></body></html>',
						'robots'   => '',
					);
				},
				'Saved title',
				'',
				function ( string $host ) use ( &$resolver_host ): array {
					$resolver_host = $host;
					return array( '93.184.216.34' );
				}
			);
			$this->assertSame( $this->origin( $generated ), $this->origin( $home_at_fetch ) );
			$this->assertSame( 'https://example.test:443', $this->origin( $generated ) );
			$this->assertSame( 'example.test', $resolver_host );
			$this->assertSame( $this->origin( $generated ), $this->origin( $transport_url ) );
		} finally {
			remove_filter( 'home_url', $home, 10 );
			remove_filter( 'post_link', $link, 10 );
			remove_filter( 'page_link', $link, 10 );
			remove_filter( 'post_type_link', $link, 10 );
		}
		$this->assertSame( 200, $result->get_status() );
		$data = $result->get_data();
		$this->assertSame( 'live_html', $data['verification']['basis'] );
		$this->assertFalse( $data['verification']['html_matches'] );
		$this->assertSame( 'not_verified', $data['verification']['description_state'] );
		$this->assertNull( $data['verification']['calculated_description'] );
		$this->assertSame( 'Still published', $data['verification']['observed_description'] );
	}

	private function controlledPost( string $title, string $description ): int {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Door',
				'post_excerpt' => '',
			)
		);
		update_post_meta( $post_id, '_seostudio_title', $title );
		if ( '' === $description ) {
			delete_post_meta( $post_id, '_seostudio_description' );
		} else {
			update_post_meta( $post_id, '_seostudio_description', $description );
		}
		return $post_id;
	}

	/**
	 * Apply through a fetcher whose transport is supplied by the test.
	 *
	 * @param callable                                       $transport
	 * @param (callable(string): array<int, string>)|null $resolver
	 */
	private function controlled( int $post_id, callable $transport, string $title, string $description, ?callable $resolver = null ): \WP_REST_Response|\WP_Error {
		$this->seedProposal( $post_id, $title, $description );
		$controller = new AppearanceController(
			new Decision(),
			new Renderer(),
			new LoopbackFetcher(
				new FetchPolicy( $resolver ?? static fn (): array => array( '93.184.216.34' ) ),
				new Extractor(),
				$transport
			),
			new HeadOutput()
		);
		$request    = new WP_REST_Request( 'POST', '/seostudio/v1/posts/' . $post_id . '/appearance/apply' );
		$request->set_param( 'post_id', $post_id );
		$request->set_param( 'confirm', true );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'confirm'              => true,
					'title'                => 'Do not publish the request body',
					'description'          => 'Do not publish the request body',
					'proposal_revision'    => 1,
					'baseline_title'       => $title,
					'baseline_description' => $description,
				)
			)
		);
		return $controller->apply( $request );
	}

	private function seedProposal( int $post_id, string $title, string $description ): int {
		$store  = new ProposalStore();
		$result = $store->commit( $post_id, $title, $description, $store->revision( $post_id ) );
		$this->assertTrue( $result['ok'] );
		return $result['revision'];
	}

	private function origin( string $url ): string {
		$parts  = wp_parse_url( $url );
		$scheme = is_array( $parts ) ? (string) ( $parts['scheme'] ?? '' ) : '';
		$host   = is_array( $parts ) ? (string) ( $parts['host'] ?? '' ) : '';
		$port   = is_array( $parts ) && isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
		return $scheme . '://' . $host . ':' . $port;
	}

	/**
	 * Dispatch one appearance route.
	 *
	 * @param array<string, mixed> $body
	 */
	private function request( string $method, int $post_id, string $path, array $body ): \WP_REST_Response|\WP_Error {
		$request = new WP_REST_Request( $method, '/seostudio/v1/posts/' . $post_id . $path );
		if ( array() !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_do_request( $request );
	}
}
