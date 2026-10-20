<?php
/**
 * Permission and homepage regressions from the Phase 3 to 5 correction pass.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Content\PageModel;
use SEOStudio\Content\Renderer;
use SEOStudio\Core\HomepageMetadata;
use SEOStudio\Core\Plugin;
use SEOStudio\Core\Settings;
use SEOStudio\Integrations\Detector;
use SEOStudio\Output\HeadOutput;
use SEOStudio\Scoring\OnPage\Scorer;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Storage\ScoresRepository;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Exercise observable REST and published-head behaviour, rather than helper internals.
 */
final class CorrectionPassTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		Plugin::init();
		do_action( 'rest_api_init' );
		Detector::clearForce();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function test_custom_permissions_apply_before_search_summary_and_pagination(): void {
		$hidden = $this->analysedPost( 'Permission fixture secret', 'private' );
		$first  = $this->analysedPost( 'Permission fixture visible A' );
		$second = $this->analysedPost( 'Permission fixture visible B' );
		$deny   = static function ( array $caps, string $cap, int $userId, array $args ) use ( $hidden ): array {
			return 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $hidden ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 4 );
		try {
			$secret = $this->listing( 'Permission fixture secret' );
			$this->assertSame( array(), $secret['items'] );
			$this->assertSame( 0, $secret['total'] );
			$this->assertSame(
				array(
					'analysed'         => 0,
					'scored'           => 0,
					'unscored'         => 0,
					'needs_reanalysis' => 0,
					'not_analysed'     => 0,
					'failed'           => 0,
					'average'          => null,
					'red'              => 0,
					'orange'           => 0,
					'green'            => 0,
				),
				$secret['summary']
			);
			$one = $this->listing( 'Permission fixture', 1 );
			$two = $this->listing( 'Permission fixture', 2 );
			$this->assertSame( 2, $one['total'] );
			$this->assertSame( 2, $one['pages'] );
			$this->assertSame( 2, $one['summary']['analysed'] );
			$this->assertSame( $one['summary'], $two['summary'] );
			$this->assertSame( array( $first ), array_column( $one['items'], 'object_id' ) );
			$this->assertSame( array( $second ), array_column( $two['items'], 'object_id' ) );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}
	}

	public function test_custom_permission_grants_are_not_excluded_by_role_sql(): void {
		$post = $this->analysedPost( 'Granted private fixture', 'private' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$grant = static function ( array $caps, string $cap, int $userId, array $args ) use ( $post ): array {
			return 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $post ? array( 'edit_posts' ) : $caps;
		};
		add_filter( 'map_meta_cap', $grant, 10, 4 );
		try {
			$data = $this->listing( 'Granted private fixture' );
			$this->assertSame( 1, $data['total'] );
			$this->assertSame( $post, $data['items'][0]['object_id'] );
		} finally {
			remove_filter( 'map_meta_cap', $grant, 10 );
		}
	}

	public function test_user_has_cap_restrictions_also_hide_aggregates(): void {
		$post = $this->analysedPost( 'Capability fixture secret' );
		$deny = static function ( array $allcaps, array $caps, array $args ) use ( $post ): array {
			if ( 'edit_post' === ( $args[0] ?? '' ) && $post === (int) ( $args[2] ?? 0 ) ) {
				$allcaps['edit_published_posts'] = false;
			}
			return $allcaps;
		};
		add_filter( 'user_has_cap', $deny, 10, 3 );
		try {
			$data = $this->listing( 'Capability fixture secret' );
			$this->assertSame( 0, $data['total'] );
			$this->assertSame( array(), $data['items'] );
			$this->assertNull( $data['summary']['average'] );
		} finally {
			remove_filter( 'user_has_cap', $deny, 10 );
		}
	}

	public function test_standard_roles_keep_their_object_access(): void {
		$owner = self::factory()->user->create( array( 'role' => 'author' ) );
		$own   = $this->analysedPost( 'Role fixture own' );
		wp_update_post( array( 'ID' => $own, 'post_author' => $owner ) );
		$this->analysedPost( 'Role fixture other' );
		wp_set_current_user( $owner );
		$this->assertSame( 1, $this->listing( 'Role fixture' )['total'] );
		foreach ( array( 'editor', 'administrator' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
			$this->assertSame( 2, $this->listing( 'Role fixture' )['total'] );
		}
	}

	public function test_static_homepage_scoring_preview_and_output_share_settings(): void {
		$post = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Page title', 'post_content' => '<p>Homepage body.</p>' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $post );
		update_option( 'blogname', 'Example Site' );
		update_option( 'blogdescription', 'Example tagline' );
		update_post_meta( $post, '_seostudio_title', 'Ignored post title' );
		update_post_meta( $post, '_seostudio_description', 'Ignored post description' );
		update_post_meta( $post, '_seostudio_canonical', home_url( '/ignored/' ) );
		update_post_meta( $post, '_seostudio_og_title', 'Social %%sitename%%' );
		update_post_meta( $post, '_seostudio_og_description', 'Social description' );
		$image = self::factory()->attachment->create( array( 'post_mime_type' => 'image/jpeg' ) );
		update_attached_file( $image, 'homepage-social.jpg' );
		wp_update_attachment_metadata( $image, array( 'file' => 'homepage-social.jpg', 'width' => 1200, 'height' => 630 ) );
		update_post_meta( $post, '_seostudio_og_image', $image );

		foreach ( array( array( 'Home %%sitename%%', 'About %%tagline%%', 'Home Example Site', 'About Example tagline' ), array( 'Updated home', 'Updated description', 'Updated home', 'Updated description' ), array( '', '', 'Example Site', 'Example tagline' ) ) as $templates ) {
			$settings = Settings::all();
			$settings['mode'] = 'owner';
			$settings['home_title'] = $templates[0];
			$settings['home_description'] = $templates[1];
			update_option( Settings::OPTION, $settings );
			$this->go_to( home_url( '/' ) );
			$expected = HomepageMetadata::resolve();
			$model = ( new Renderer() )->renderPost( $post );
			$preview = ( new Renderer() )->renderDraft( get_post( $post ), '<p>Unsaved body</p>', array( 'seo_title' => 'Ignored unsaved title', 'seo_description' => 'Ignored unsaved description' ) );
			$this->assertSame( $templates[2], $model->titleTag );
			$this->assertSame( $templates[3], $model->metaDescription );
			$this->assertSame( $model->titleTag, $preview->titleTag );
			$this->assertSame( $expected['meta_description'], $preview->metaDescription );
			$this->assertSame( $expected['canonical'], $model->canonical );
			$this->assertSame( esc_html( $model->titleTag ), ( new HeadOutput() )->documentTitle( '' ) );
			ob_start();
			( new HeadOutput() )->render();
			$html = (string) ob_get_clean();
			$this->assertStringContainsString( 'name="description" content="' . esc_attr( $model->metaDescription ) . '"', $html );
			$this->assertStringContainsString( 'rel="canonical" href="' . esc_url( $model->canonical ) . '"', $html );
			$this->assertStringContainsString( 'Social Example Site', $html );
			$this->assertStringContainsString( 'Social description', $html );
			$this->assertStringContainsString( 'homepage-social.jpg', $html );
			$this->assertStringContainsString( '"@type":"WebSite"', $html );
		}
		$this->assertSame( 'Example Site', $model->titleTag );
		$this->assertSame( 'Example tagline', $model->metaDescription );
		$settings['mode'] = 'analysis';
		update_option( Settings::OPTION, $settings );
		$analysis = ( new Renderer() )->renderPost( $post );
		$this->assertSame( '', $analysis->titleTag );
		$this->assertSame( 'not_observed', $analysis->titleEvidence );
	}

	private function analysedPost( string $title, string $status = 'publish' ): int {
		$post = self::factory()->post->create( array( 'post_title' => $title, 'post_status' => $status, 'post_author' => get_current_user_id() ) );
		update_post_meta( $post, '_seostudio_focus_keyphrase', 'fixture' );
		$model = new PageModel( url: (string) get_permalink( $post ), titleTag: $title );
		$page = ( new PagesRepository() )->storeModel( $post, $model, '' );
		( new ScoresRepository() )->insert( $page, 'fixture', ( new Scorer() )->score( $model, 'fixture' ), SEOSTUDIO_VERSION );
		return $post;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function listing( string $search, int $page = 1 ): array {
		$request = new WP_REST_Request( 'GET', '/seostudio/v1/pages' );
		foreach ( array( 'search' => $search, 'page' => $page, 'per_page' => 1, 'orderby' => 'title', 'order' => 'asc' ) as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		return $response->get_data();
	}
}
