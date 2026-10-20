<?php
/**
 * Positive proofs for navigation, destinations, aliases, freshness, and disclosure.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Architecture\DestinationResolver;
use SEOStudio\Architecture\LinkSource;
use SEOStudio\Architecture\NavigationSurvey;
use SEOStudio\Architecture\SiteSurvey;
use SEOStudio\Architecture\UrlKey;
use SEOStudio\Core\Plugin;
use SEOStudio\Core\Settings;
use SEOStudio\Recommendations\PrioritiesReport;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Content\PageModel;
use WP_UnitTestCase;

/**
 * L7, L8, and L9 must pass as behaviour, not only as the absence of an exception.
 */
final class SiteIntelligenceTest extends WP_UnitTestCase {
	private int $block_navigation = 0;

	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		Plugin::init();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( Settings::OPTION );
		update_option( 'permalink_structure', '/%postname%/' );
		flush_rewrite_rules( false );
		$this->delete_content();
		$menus = wp_get_nav_menus();
		if ( is_array( $menus ) ) {
			foreach ( $menus as $menu ) {
				wp_delete_nav_menu( (int) $menu->term_id );
			}
		}
		remove_theme_mod( 'nav_menu_locations' );
		$this->block_navigation = 0;
		add_filter( 'pre_get_block_templates', array( $this, 'empty_block_templates' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_get_block_templates', array( $this, 'empty_block_templates' ), 10 );
		remove_filter( 'pre_get_block_templates', array( $this, 'referenced_block_templates' ), 10 );
		parent::tear_down();
	}

	/**
	 * Bundled block themes contain navigation blocks this fixture does not assign.
	 *
	 * @param mixed $templates Previous short-circuit value.
	 * @param mixed $query     Template query.
	 * @param mixed $type      Template type.
	 * @return array<int, object>
	 */
	public function empty_block_templates( $templates, $query, $type ): array {
		unset( $templates, $query, $type );
		return array();
	}

	/**
	 * @param mixed $templates Previous short-circuit value.
	 * @param mixed $query     Template query.
	 * @param mixed $type      Template type.
	 * @return array<int, object>
	 */
	public function referenced_block_templates( $templates, $query, $type ): array {
		unset( $templates, $query, $type );
		if ( $this->block_navigation < 1 ) {
			return array();
		}
		return array(
			(object) array(
				'content' => '<!-- wp:navigation {"ref":' . $this->block_navigation . '} /-->',
			),
		);
	}

	private function delete_content(): void {
		foreach ( array( 'post', 'page', 'wp_navigation', 'wp_template', 'wp_template_part' ) as $type ) {
			$posts = get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => 'any',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'suppress_filters' => true,
				)
			);
			if ( ! is_array( $posts ) ) {
				continue;
			}
			foreach ( $posts as $id ) {
				wp_delete_post( (int) $id, true );
			}
		}
	}

	public function test_l7_assigned_menus_count_and_unassigned_menus_do_not(): void {
		$about = self::factory()->post->create(
			array(
				'post_title'  => 'About page',
				'post_name'   => 'about-page',
				'post_status' => 'publish',
			)
		);
		$only  = self::factory()->post->create(
			array(
				'post_title'  => 'Menu only page',
				'post_name'   => 'menu-only',
				'post_status' => 'publish',
			)
		);
		$home  = self::factory()->post->create(
			array(
				'post_title'  => 'Home page',
				'post_name'   => 'home-page',
				'post_status' => 'publish',
			)
		);

		$assigned = wp_create_nav_menu( 'Assigned' );
		$hidden   = wp_create_nav_menu( 'Unassigned' );
		$this->assertGreaterThan( 0, $assigned );
		wp_update_nav_menu_item(
			$assigned,
			0,
			array(
				'menu-item-title'  => 'About',
				'menu-item-url'    => (string) get_permalink( $about ),
				'menu-item-status' => 'publish',
			)
		);
		wp_update_nav_menu_item(
			$hidden,
			0,
			array(
				'menu-item-title'  => 'Hidden',
				'menu-item-url'    => (string) get_permalink( $only ),
				'menu-item-status' => 'publish',
			)
		);
		set_theme_mod( 'nav_menu_locations', array( 'primary' => $assigned ) );
		$this->stamp( $home, array() );
		$this->stamp( $about, array() );
		$this->stamp( $only, array() );

		$survey = ( new NavigationSurvey() )->collect();
		$hrefs  = array_column( $survey['links'], 'href' );
		$this->assertContains( (string) get_permalink( $about ), $hrefs );
		$this->assertNotContains( (string) get_permalink( $only ), $hrefs );
		$this->assertSame( 1, $survey['unassigned'] );

		$report    = ( new SiteSurvey() )->report();
		$only_row  = $this->row( $report, $only );
		$about_row = $this->row( $report, $about );
		$this->assertSame( 'orphan', $only_row['status'] );
		$this->assertSame( 'orphan', $about_row['status'] );
		$this->assertSame( 0, $about_row['template_referrers'] );
		$this->assertSame( 'high', $about_row['confidence'] );

		$this->stamp(
			$home,
			array(
				array(
					'href'   => (string) get_permalink( $about ),
					'anchor' => 'About',
					'kind'   => 'template',
				),
			)
		);
		$observed = $this->row( ( new SiteSurvey() )->report(), $about );
		$this->assertSame( 'template_only', $observed['status'] );
		$this->assertSame( 1, $observed['template_referrers'] );
	}

	public function test_l7_repeated_menu_observation_does_not_inflate_referrers(): void {
		$about = self::factory()->post->create(
			array(
				'post_title'  => 'Shared about',
				'post_status' => 'publish',
			)
		);
		$pages = array( $about );
		for ( $i = 0; $i < 3; $i++ ) {
			$pages[] = self::factory()->post->create(
				array(
					'post_title'  => 'Rendered ' . $i,
					'post_status' => 'publish',
				)
			);
		}
		$menu = wp_create_nav_menu( 'Shared' );
		wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'  => 'About',
				'menu-item-url'    => (string) get_permalink( $about ),
				'menu-item-status' => 'publish',
			)
		);
		set_theme_mod( 'nav_menu_locations', array( 'primary' => $menu ) );
		$rendered = array_slice( $pages, 1 );
		foreach ( $rendered as $index => $page_id ) {
			$links = array(
				array(
					'href'   => (string) get_permalink( $about ),
					'anchor' => 'About',
					'kind'   => 'template',
				),
			);
			if ( $index === count( $rendered ) - 1 ) {
				$links[] = array(
					'href'   => (string) get_permalink( $about ),
					'anchor' => 'About',
					'kind'   => 'contextual',
				);
				$links[] = array(
					'href'   => (string) get_permalink( $about ),
					'anchor' => 'About again',
					'kind'   => 'contextual',
				);
			}
			$this->stamp( (int) $page_id, $links );
		}
		$this->stamp( $about, array() );
		$row = $this->row( ( new SiteSurvey() )->report(), $about );
		$this->assertSame( 1, $row['template_referrers'] );
		$this->assertSame( 1, $row['template_occurrences'] );
		$this->assertSame( 1, $row['contextual_referrers'] );
		$this->assertSame( 2, $row['contextual_occurrences'] );
		$this->assertSame( 'weak', $row['status'] );
	}

	public function test_l7_block_navigation_requires_a_template_reference_and_a_published_observation(): void {
		remove_filter( 'pre_get_block_templates', array( $this, 'empty_block_templates' ), 10 );
		$about  = self::factory()->post->create(
			array(
				'post_title'  => 'Block about',
				'post_status' => 'publish',
			)
		);
		$hidden = self::factory()->post->create(
			array(
				'post_title'  => 'Unreferenced target',
				'post_status' => 'publish',
			)
		);
		$home   = self::factory()->post->create(
			array(
				'post_title'  => 'Block home',
				'post_status' => 'publish',
			)
		);
		$used   = wp_insert_post(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => 'Used navigation',
				'post_content' => '<!-- wp:navigation-link {"label":"About","url":"' . esc_url( (string) get_permalink( $about ) ) . '"} /-->',
			)
		);
		wp_insert_post(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => 'Unused navigation',
				'post_content' => '<!-- wp:navigation-link {"label":"Hidden","url":"' . esc_url( (string) get_permalink( $hidden ) ) . '"} /-->',
			)
		);
		$this->assertGreaterThan( 0, $used );
		$this->block_navigation = (int) $used;
		add_filter( 'pre_get_block_templates', array( $this, 'referenced_block_templates' ), 10, 3 );

		$hrefs = array_column( ( new NavigationSurvey() )->collect()['links'], 'href' );
		$this->assertContains( (string) get_permalink( $about ), $hrefs );
		$this->assertNotContains( (string) get_permalink( $hidden ), $hrefs );

		$this->stamp( $home, array() );
		$this->stamp( $about, array() );
		$this->stamp( $hidden, array() );
		$this->assertSame( 'orphan', $this->row( ( new SiteSurvey() )->report(), $about )['status'] );
		$this->assertSame( 'orphan', $this->row( ( new SiteSurvey() )->report(), $hidden )['status'] );

		$this->stamp(
			$home,
			array(
				array(
					'href'   => (string) get_permalink( $about ),
					'anchor' => 'About',
					'kind'   => 'template',
				),
			)
		);
		$observed = $this->row( ( new SiteSurvey() )->report(), $about );
		$this->assertSame( 'template_only', $observed['status'] );
		$this->assertSame( 1, $observed['template_referrers'] );
		$this->assertSame( 1, $observed['template_occurrences'] );
		$this->assertSame( 'orphan', $this->row( ( new SiteSurvey() )->report(), $hidden )['status'] );
	}

	public function test_unreferenced_navigation_blocks_a_high_confidence_orphan(): void {
		remove_filter( 'pre_get_block_templates', array( $this, 'empty_block_templates' ), 10 );
		add_filter(
			'pre_get_block_templates',
			static function (): array {
				return array(
					(object) array(
						'content' => '<!-- wp:navigation {"overlayMenu":"never"} /-->',
					),
				);
			},
			10,
			3
		);
		try {
			$post = self::factory()->post->create(
				array(
					'post_title'  => 'Unverified orphan',
					'post_status' => 'publish',
				)
			);
			$this->stamp( $post, array() );
			$row = $this->pageRow( $post );
			$this->assertSame( 'unknown', $row['status'] );
			$this->assertNotSame( 'orphan', $row['status'] );
			$this->assertSame( 'limited', $row['confidence'] );
			$this->assertStringContainsString( 'navigation block', (string) $row['evidence'] );
			$notes = implode( ' ', ( new SiteSurvey() )->report()['notes'] );
			$this->assertStringContainsString( 'navigation block', $notes );
			$this->assertSame( array(), ( new SiteSurvey() )->report()['orphans'] );
		} finally {
			remove_all_filters( 'pre_get_block_templates' );
			add_filter( 'pre_get_block_templates', array( $this, 'empty_block_templates' ), 10, 3 );
		}
	}

	public function test_l8_classifies_local_destinations_without_fetching(): void {
		$post = self::factory()->post->create(
			array(
				'post_title'  => 'Queryable',
				'post_status' => 'publish',
			)
		);
		$term = self::factory()->term->create(
			array(
				'taxonomy' => 'category',
				'name'     => 'Desk',
				'slug'     => 'desk',
			)
		);
		$this->assertGreaterThan( 0, $term );
		$uploads_filter = static function ( array $dirs ): array {
			$base            = untrailingslashit( home_url( '/wp-content/uploads' ) );
			$dirs['baseurl'] = $base;
			$dirs['url']     = $base;
			return $dirs;
		};
		add_filter( 'upload_dir', $uploads_filter );
		wp_upload_dir( null, false, true );
		$pdf_url = home_url( '/wp-content/uploads/guide.pdf' );
		$pdf     = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'Guide',
				'guid'           => $pdf_url,
			)
		);
		update_post_meta( (int) $pdf, '_wp_attached_file', 'guide.pdf' );
		$renamed = self::factory()->post->create(
			array(
				'post_title'  => 'Renamed',
				'post_name'   => 'old-slug',
				'post_status' => 'publish',
			)
		);
		wp_update_post(
			array(
				'ID'        => $renamed,
				'post_name' => 'new-slug',
			)
		);

		$page    = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Fixed page',
				'post_status' => 'publish',
			)
		);
		$private = self::factory()->post->create(
			array(
				'post_title'  => 'Private vault title',
				'post_status' => 'private',
				'post_name'   => 'private-vault',
			)
		);
		$draft   = self::factory()->post->create(
			array(
				'post_title'  => 'Draft destination',
				'post_status' => 'draft',
				'post_name'   => 'draft-destination',
			)
		);
		$trashed = self::factory()->post->create(
			array(
				'post_title'  => 'Trashed destination',
				'post_status' => 'publish',
				'post_name'   => 'trashed-destination',
			)
		);
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_status' => 'trash' ), array( 'ID' => $trashed ) );
		clean_post_cache( $trashed );

		$home      = home_url( '/' );
		$resolver  = new DestinationResolver();
		try {
			$published = $resolver->classify( (string) get_permalink( $post ), $home );
			$query     = $resolver->classify( home_url( '/?p=' . $post ), $home );
			$page_id   = $resolver->classify( home_url( '/?page_id=' . $page ), $home );
			$archive   = $resolver->classify( home_url( '/category/desk/' ), $home );
			$media     = $resolver->classify( $pdf_url, $home );
			$redirect  = $resolver->classify( home_url( '/old-slug/' ), $home );
			$missing   = $resolver->classify( home_url( '/?p=999999' ), $home );
			$unknown   = $resolver->classify( home_url( '/not-a-real-route/' ), $home );
			$system    = $resolver->classify( home_url( '/wp-login.php' ), $home );
			$external  = $resolver->classify( 'https://other.test/away', $home );
			$hidden    = $resolver->classify( (string) get_permalink( $private ), $home );
			$drafted   = $resolver->classify( home_url( '/draft-destination/' ), $home );
			$gone      = $resolver->classify( home_url( '/?p=' . $trashed ), $home );

			$this->assertSame( 'published', $published['status'] );
			$this->assertSame( 'query', $query['status'] );
			$this->assertSame( 'query', $page_id['status'] );
			$this->assertSame( 'archive', $archive['status'] );
			$this->assertSame( 'media', $media['status'] );
			$this->assertStringContainsString( 'PDF', $media['reason'] );
			$this->assertSame( 'redirect', $redirect['status'] );
			$this->assertSame( 'missing', $missing['status'] );
			$this->assertSame( 'unknown', $unknown['status'] );
			$this->assertStringContainsString( 'not a confirmed broken link', $unknown['reason'] );
			$this->assertSame( 'system', $system['status'] );
			$this->assertSame( 'external', $external['status'] );
			$this->assertNotSame( 'missing', $unknown['status'] );
			$this->assertSame( 'non_public', $hidden['status'] );
			$this->assertSame( 'non_public', $drafted['status'] );
			$this->assertSame( 'missing', $gone['status'] );
			$this->assertNotSame( 'missing', $hidden['status'] );
		} finally {
			remove_filter( 'upload_dir', $uploads_filter );
			wp_upload_dir( null, false, true );
		}
	}

	public function test_l9_uses_the_public_home_and_explicit_aliases_only(): void {
		$home = 'https://example.test/';
		$this->assertTrue( UrlKey::sameSite( UrlKey::identity( 'https://example.test/about' ), $home ) );
		$this->assertFalse( UrlKey::sameSite( UrlKey::identity( 'https://www.example.test/about' ), $home ) );
		$this->assertTrue(
			UrlKey::sameSite(
				UrlKey::identity( 'https://www.example.test/about' ),
				$home,
				array( 'www.example.test' )
			)
		);
		$nested = 'https://example.test/blog/';
		$this->assertTrue( UrlKey::sameSite( UrlKey::identity( 'https://example.test/blog/about' ), $nested ) );
		$this->assertFalse( UrlKey::sameSite( UrlKey::identity( 'https://example.test/shop' ), $nested ) );
		$this->assertFalse( UrlKey::sameSite( UrlKey::identity( 'https://cdn.example.test/about' ), $home ) );
		$this->assertTrue( UrlKey::sameSite( UrlKey::identity( 'https://example.test:8443/about' ), 'https://example.test:8443/' ) );
		$this->assertFalse( UrlKey::sameSite( UrlKey::identity( 'https://example.test/about' ), 'https://example.test:8443/' ) );
		$this->assertSame( '/blog', \SEOStudio\Architecture\SiteBoundary::publicPath( 'https://example.test/blog/' ) );
		$this->assertTrue( \SEOStudio\Architecture\SiteBoundary::contains( 'https://example.test/blog/about', 'https://example.test/blog/' ) );
		$this->assertFalse( \SEOStudio\Architecture\SiteBoundary::contains( 'https://example.test/shop', 'https://example.test/blog/' ) );
		$this->assertTrue( \SEOStudio\Architecture\SiteBoundary::contains( 'https://example.test/about', 'https://example.test/' ) );
		$this->assertSame( 'external', ( new DestinationResolver() )->classify( 'https://www.example.test/about', home_url( '/' ) )['status'] );

		$settings                 = Settings::all();
		$settings['host_aliases'] = array( 'www.example.test' );
		update_option( Settings::OPTION, $settings );
		$resolver = new DestinationResolver();
		$public   = 'https://example.test/';
		$ported   = 'https://example.test:8443/';
		$this->assertTrue( \SEOStudio\Architecture\SiteBoundary::contains( 'https://www.example.test/about', $public ) );
		$this->assertTrue( \SEOStudio\Architecture\SiteBoundary::contains( 'https://www.example.test:8443/about', $ported ) );
		$this->assertFalse( \SEOStudio\Architecture\SiteBoundary::contains( 'https://www.example.test/about', $ported ) );
		$this->assertSame( 'unknown', $resolver->classify( 'https://www.example.test/about', $public )['status'] );
		$this->assertSame( 'external', $resolver->classify( 'https://cdn.example.test/file', $public )['status'] );
		$clean = Settings::sanitize(
			array(
				'host_aliases' => array( 'WWW.Example.test', 'https://evil.test/path', 'cdn.example.test' ),
			)
		);
		$this->assertSame( array( 'www.example.test', 'cdn.example.test' ), $clean['host_aliases'] );
	}

	public function test_link_freshness_ignores_keyphrase_and_withholds_content_and_theme_changes(): void {
		$post = self::factory()->post->create(
			array(
				'post_title'   => 'Observed',
				'post_content' => 'Original body',
				'post_status'  => 'publish',
			)
		);
		$this->stamp(
			$post,
			array(
				array(
					'href'   => home_url( '/next/' ),
					'anchor' => 'Next',
					'kind'   => 'contextual',
				),
			)
		);
		$this->assertSame( '', (string) get_post_meta( $post, '_seostudio_focus_keyphrase', true ) );
		$this->assertTrue( LinkSource::isFresh( $this->model( $post ), $post ) );

		update_post_meta( $post, '_seostudio_focus_keyphrase', 'rank tracker' );
		$this->assertTrue( LinkSource::isFresh( $this->model( $post ), $post ) );

		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => 'Edited body',
			)
		);
		$this->assertFalse( LinkSource::isFresh( $this->model( $post ), $post ) );

		$this->stamp(
			$post,
			array(
				array(
					'href'   => home_url( '/next/' ),
					'anchor' => 'Next',
					'kind'   => 'contextual',
				),
			)
		);
		$before  = LinkSource::current( $post );
		$current = get_stylesheet();
		$other   = $this->otherTheme();
		switch_theme( $other );
		try {
			$this->assertNotSame( $before, LinkSource::current( $post ) );
			$this->assertFalse( LinkSource::isFresh( $this->model( $post ), $post ) );
		} finally {
			switch_theme( $current );
		}

		$menu = wp_create_nav_menu( 'Later menu' );
		$this->assertGreaterThan( 0, $menu );
		set_theme_mod( 'nav_menu_locations', array( 'primary' => $menu ) );
		wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-title'  => 'Next',
				'menu-item-url'    => home_url( '/next/' ),
				'menu-item-status' => 'publish',
			)
		);
		$this->assertFalse( LinkSource::isFresh( $this->model( $post ), $post ) );
		$this->stamp(
			$post,
			array(
				array(
					'href'   => home_url( '/next/' ),
					'anchor' => 'Next',
					'kind'   => 'contextual',
				),
			)
		);
		$this->assertTrue( LinkSource::isFresh( $this->model( $post ), $post ) );

		global $wpdb;
		$stored = $this->model( $post );
		unset( $stored['link_source_hash'] );
		$wpdb->update(
			$wpdb->prefix . 'seostudio_pages',
			array( 'page_model' => wp_json_encode( $stored ) ),
			array(
				'object_type' => 'post',
				'object_id'   => $post,
			)
		);
		$this->assertFalse( LinkSource::isFresh( $this->model( $post ), $post ) );
		$this->stamp(
			$post,
			array(
				array(
					'href'   => home_url( '/next/' ),
					'anchor' => 'Next',
					'kind'   => 'contextual',
				),
			)
		);
		$this->assertTrue( LinkSource::isFresh( $this->model( $post ), $post ) );
	}

	public function test_footer_only_is_template_and_incomplete_coverage_is_not_an_orphan(): void {
		$target = self::factory()->post->create(
			array(
				'post_title'  => 'Target',
				'post_status' => 'publish',
			)
		);
		$footer = self::factory()->post->create(
			array(
				'post_title'  => 'Footer source',
				'post_status' => 'publish',
			)
		);
		$this->stamp( $target, array() );
		$this->stamp(
			$footer,
			array(
				array(
					'href'   => (string) get_permalink( $target ),
					'anchor' => 'Target',
					'kind'   => 'template',
				),
			)
		);
		$row = $this->pageRow( $target );
		$this->assertSame( 'template_only', $row['status'] );
		$this->assertNotSame( 'orphan', $row['status'] );

		self::factory()->post->create(
			array(
				'post_title'  => 'No observation',
				'post_status' => 'publish',
			)
		);
		$again = $this->pageRow( $target );
		$this->assertSame( 'template_only', $again['status'] );
		$this->assertSame( 'limited', $again['confidence'] );
		$this->assertNotSame( 'orphan', $again['status'] );
		$report = ( new SiteSurvey() )->report();
		$this->assertNull( $report['counts']['orphans'] );
		$this->assertSame( array(), $report['orphans'] );
		$unchecked = null;
		foreach ( ( new SiteSurvey() )->evaluated()['pages'] as $page ) {
			if ( is_array( $page ) && 'No observation' === (string) ( $page['title'] ?? '' ) ) {
				$unchecked = $page;
			}
		}
		$this->assertIsArray( $unchecked );
		$this->assertSame( 'unknown', $unchecked['status'] );
		$this->assertSame( 'Not yet verified.', $unchecked['observation'] );
		$this->assertSame( 'limited', $unchecked['confidence'] );
		$this->assertStringContainsString( 'no stored analysis', (string) $unchecked['evidence'] );
	}

	public function test_true_orphan_requires_complete_published_coverage(): void {
		$orphan = self::factory()->post->create(
			array(
				'post_title'  => 'Lonely',
				'post_status' => 'publish',
			)
		);
		$other  = self::factory()->post->create(
			array(
				'post_title'   => 'Companion',
				'post_content' => 'No links here',
				'post_status'  => 'publish',
			)
		);
		update_post_meta( $other, '_seostudio_focus_keyphrase', 'shared phrase' );
		update_post_meta( $orphan, '_seostudio_focus_keyphrase', 'shared phrase' );
		$this->stamp( $orphan, array() );
		$this->stamp( $other, array() );
		$row = $this->row( ( new SiteSurvey() )->report(), $orphan );
		$this->assertSame( 'orphan', $row['status'] );
		$this->assertSame( 'high', $row['confidence'] );
		$this->assertSame( 'No verified incoming links were found.', $row['observation'] );
		$this->assertStringContainsString( '2 of 2', $row['evidence'] );
		$this->assertNotEmpty( $row['candidates'] );
		$this->assertLessThanOrEqual( 3, count( $row['candidates'] ) );
		$this->assertStringContainsString( 'Companion', (string) $row['action'] );
	}

	public function test_an_orphan_without_a_stored_topic_names_no_candidate(): void {
		$orphan = self::factory()->post->create(
			array(
				'post_title'  => 'No topic',
				'post_status' => 'publish',
			)
		);
		$other  = self::factory()->post->create(
			array(
				'post_title'  => 'Also none',
				'post_status' => 'publish',
			)
		);
		$this->stamp( $orphan, array() );
		$this->stamp( $other, array() );
		$row = $this->row( ( new SiteSurvey() )->report(), $orphan );
		$this->assertSame( 'orphan', $row['status'] );
		$this->assertSame( array(), $row['candidates'] );
		$this->assertStringContainsString( 'no candidate', strtolower( (string) $row['action'] ) );
	}

	public function test_unknown_graph_destinations_stay_out_of_the_missing_list(): void {
		$source = self::factory()->post->create(
			array(
				'post_title'  => 'Source page',
				'post_status' => 'publish',
			)
		);
		$other  = self::factory()->post->create(
			array(
				'post_title'  => 'Other page',
				'post_status' => 'publish',
			)
		);
		$this->stamp(
			$source,
			array(
				array(
					'href'   => home_url( '/not-a-real-route/' ),
					'anchor' => 'Mystery',
					'kind'   => 'contextual',
				),
				array(
					'href'   => home_url( '/?p=999999' ),
					'anchor' => 'Gone',
					'kind'   => 'contextual',
				),
			)
		);
		$this->stamp( $other, array() );
		$report     = ( new SiteSurvey() )->report();
		$unresolved = (string) wp_json_encode( $report['unresolved'] );
		$unverified = (string) wp_json_encode( $report['unverified'] );
		$this->assertStringContainsString( 'not-a-real-route', $unverified );
		$this->assertStringNotContainsString( 'not-a-real-route', $unresolved );
		$this->assertStringContainsString( '999999', $unresolved );
		$this->assertStringNotContainsString( '999999', $unverified );
		$found = false;
		foreach ( $report['unresolved'] as $row ) {
			if ( is_array( $row ) && str_contains( (string) ( $row['href'] ?? '' ), '999999' ) ) {
				$this->assertSame( 'missing', $row['resolution'] );
				$found = true;
			}
		}
		$this->assertTrue( $found );
		$this->assertStringContainsString( 'not confirmed broken', implode( ' ', $report['notes'] ) );
	}

	public function test_private_destinations_are_not_missing_and_are_not_disclosed(): void {
		$author      = self::factory()->user->create( array( 'role' => 'author' ) );
		$visible     = self::factory()->post->create(
			array(
				'post_title'  => 'Author visible page',
				'post_status' => 'publish',
				'post_author' => $author,
				'post_name'   => 'author-visible',
			)
		);
		$private     = self::factory()->post->create(
			array(
				'post_title'  => 'Private vault title',
				'post_status' => 'private',
				'post_name'   => 'private-vault-page',
				'post_author' => self::factory()->user->create( array( 'role' => 'editor' ) ),
			)
		);
		$private_url = (string) get_permalink( $private );
		$this->assertSame( 'non_public', ( new DestinationResolver() )->classify( $private_url, home_url( '/' ) )['status'] );
		$this->stamp(
			$visible,
			array(
				array(
					'href'   => $private_url,
					'anchor' => 'Vault',
					'kind'   => 'contextual',
				),
			)
		);
		wp_set_current_user( $author );
		$report  = ( new SiteSurvey() )->report();
		$encoded = (string) wp_json_encode( $report );
		$this->assertStringNotContainsString( 'Private vault title', $encoded );
		$this->assertStringNotContainsString( 'private-vault-page', (string) wp_json_encode( $report['unresolved'] ) );
		$this->assertStringNotContainsString( 'private-vault-page', (string) wp_json_encode( $report['unverified'] ) );
		$visible_row = $this->row( $report, $visible );
		$this->assertContains( $visible_row['status'], array( 'orphan', 'front' ) );
	}

	public function test_editors_and_authors_do_not_receive_hidden_page_details(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$mine   = self::factory()->post->create(
			array(
				'post_title'  => 'Author visible page',
				'post_status' => 'publish',
				'post_author' => $author,
			)
		);
		$secret = self::factory()->post->create(
			array(
				'post_title'  => 'Secret other title',
				'post_status' => 'publish',
				'post_author' => $editor,
			)
		);
		$this->stamp(
			$secret,
			array(
				array(
					'href'   => (string) get_permalink( $mine ),
					'anchor' => 'Visible',
					'kind'   => 'contextual',
				),
			)
		);
		$this->stamp( $mine, array() );
		update_post_meta( $secret, '_seostudio_focus_keyphrase', 'shared phrase' );
		update_post_meta( $mine, '_seostudio_focus_keyphrase', 'shared phrase' );

		wp_set_current_user( $author );
		$report  = ( new SiteSurvey() )->report();
		$encoded = (string) wp_json_encode( $report );
		$this->assertStringNotContainsString( 'Secret other title', $encoded );
		$this->assertStringContainsString( 'Author visible page', $encoded );
		$visible = $this->row( $report, $mine );
		$this->assertGreaterThan( 0, $visible['contextual_referrers'] );
		$this->assertSame( array(), $visible['contextual_referrer_ids'] );

		$priorities = (string) wp_json_encode( ( new PrioritiesReport() )->report() );
		$this->assertStringNotContainsString( 'Secret other title', $priorities );

		$blocked = static function ( array $caps, string $cap, int $user_id, array $args ) use ( $secret ): array {
			unset( $user_id );
			if ( 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $secret ) {
				return array( 'do_not_allow' );
			}
			return $caps;
		};
		wp_set_current_user( $editor );
		add_filter( 'map_meta_cap', $blocked, 10, 4 );
		$custom = (string) wp_json_encode( ( new SiteSurvey() )->report() );
		remove_filter( 'map_meta_cap', $blocked, 10 );
		$this->assertStringNotContainsString( 'Secret other title', $custom );
	}

	/**
	 * @param array<int, array<string, mixed>> $links
	 */
	private function stamp( int $postId, array $links ): void {
		$model = new PageModel(
			url: (string) get_permalink( $postId ),
			renderMode: 'live',
			links: $links
		);
		( new PagesRepository() )->storeModel( $postId, $model, $model->inputHash() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function model( int $postId ): array {
		$row = ( new PagesRepository() )->findByObject( 'post', $postId );
		$this->assertIsArray( $row );
		$decoded = json_decode( (string) $row['page_model'], true );
		$this->assertIsArray( $decoded );
		return $decoded;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function pageRow( int $postId ): array {
		$pages = ( new SiteSurvey() )->evaluated()['pages'];
		$this->assertArrayHasKey( 'post:' . $postId, $pages );
		return $pages[ 'post:' . $postId ];
	}

	/**
	 * @param array<string, mixed> $report
	 * @return array<string, mixed>
	 */
	private function row( array $report, int $postId ): array {
		foreach ( $report['pages'] ?? array() as $row ) {
			if ( is_array( $row ) && (int) ( $row['post_id'] ?? 0 ) === $postId ) {
				return $row;
			}
		}
		foreach ( array( 'orphans', 'weak', 'template_only' ) as $list ) {
			foreach ( $report[ $list ] ?? array() as $row ) {
				if ( is_array( $row ) && (int) ( $row['post_id'] ?? 0 ) === $postId ) {
					return $row;
				}
			}
		}
		$this->fail( 'Missing row for ' . $postId );
	}

	private function otherTheme(): string {
		foreach ( array( 'twentytwentyfour', 'twentytwentythree', 'twentytwentytwo' ) as $theme ) {
			if ( wp_get_theme( $theme )->exists() && get_stylesheet() !== $theme ) {
				return $theme;
			}
		}
		$this->fail( 'A second theme is required to prove theme changes withhold link evidence.' );
	}
}
