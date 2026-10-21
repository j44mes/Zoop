<?php
/**
 * Activation, mode switches, migration and upgrade leave the site as it was.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Regression;

use SEOStudio\Core\Plugin;
use SEOStudio\Core\Settings;
use SEOStudio\Core\Upgrader;
use SEOStudio\Integrations\Importers\Yoast;
use WP_UnitTestCase;

require_once __DIR__ . '/snapshot.php';

/**
 * The preservation guarantee from the specification, asserted on a fixture site.
 */
final class RegressionTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );
		// Taxonomy permastructs are built on init; rebuild them so the baseline uses pretty links too.
		create_initial_taxonomies();
		update_option( 'blog_public', '1' );

		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		for ( $i = 1; $i <= 6; ++$i ) {
			$post_id = self::factory()->post->create(
				array(
					'post_author'  => $author,
					'post_status'  => 'publish',
					'post_title'   => 'Fixture post ' . $i,
					'post_content' => '<!-- wp:paragraph --><p>Body ' . $i . '</p><!-- /wp:paragraph -->',
				)
			);
			update_post_meta( $post_id, '_yoast_wpseo_title', '%%title%% %%sep%% %%sitename%%' );
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', 'Yoast description ' . $i );
			update_post_meta( $post_id, 'builder_layout', array( 'rows' => array( 'section ' . $i ) ) );
		}
		self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Fixture page',
			)
		);
		self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$menu = wp_create_nav_menu( 'Fixture menu ' . wp_generate_password( 8, false ) );
		wp_update_nav_menu_item(
			(int) $menu,
			0,
			array(
				'menu-item-title'  => 'Home',
				'menu-item-url'    => home_url( '/' ),
				'menu-item-status' => 'publish',
			)
		);
	}

	public function test_activation_changes_nothing(): void {
		$before = Snapshot::take();
		Plugin::activate();
		Plugin::init();
		$this->assertSame( array(), Snapshot::diff( $before, Snapshot::take() ) );
	}

	public function test_owner_mode_changes_only_its_own_tags_and_switching_back_restores_the_head(): void {
		Plugin::activate();
		$before = Snapshot::take();

		$this->mode( 'owner' );
		$owner = Snapshot::take();
		$this->assertSame( array(), Snapshot::diff( $before, $owner, true ) );
		$this->assertNotSame( $before['heads'], $owner['heads'], 'Owner mode should print its own tags.' );

		$this->mode( 'analysis' );
		$this->assertSame( array(), Snapshot::diff( $before, Snapshot::take() ) );
	}

	public function test_migration_leaves_content_and_source_meta_untouched(): void {
		Plugin::activate();
		$before = Snapshot::take();
		$result = ( new Yoast() )->import( 0, 200 );
		$this->assertGreaterThan( 0, $result['processed'] );
		$this->assertSame( array(), Snapshot::diff( $before, Snapshot::take() ) );
	}

	public function test_a_schema_upgrade_leaves_the_site_untouched(): void {
		Plugin::activate();
		$settings                   = Settings::all();
		// Version 2 to 3 is this release's upgrade. Version 1 also runs ALTER TABLE, whose implicit
		// commit would leak this test's fixtures into later tests; ReviewFixesTest covers that step.
		$settings['schema_version'] = 2;
		update_option( Settings::OPTION, $settings, true );

		$before = Snapshot::take();
		Upgrader::maybeUpgrade();
		$this->assertSame( \SEOStudio\Core\Upgrader::CURRENT, Settings::all()['schema_version'] );
		$this->assertSame( array(), Snapshot::diff( $before, Snapshot::take() ) );
	}

	private function mode( string $mode ): void {
		$settings         = Settings::all();
		$settings['mode'] = $mode;
		update_option( Settings::OPTION, $settings, true );
	}
}
