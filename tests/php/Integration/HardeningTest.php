<?php
/**
 * Access control, preservation, and the uninstall flag.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Core\Plugin;
use SEOStudio\Core\Settings;
use SEOStudio\Core\Uninstaller;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Anonymous callers cannot read settings. Mode changes do not touch post content.
 */
final class HardeningTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		do_action( 'init' );
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		Plugin::activate();
		parent::tear_down();
	}

	public function test_settings_and_export_require_an_administrator(): void {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/settings' ) )->get_status() );
		$this->assertSame( 401, rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/export' ) )->get_status() );

		wp_set_current_user( $subscriber );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/settings' ) )->get_status() );

		wp_set_current_user( $admin );
		$this->assertSame( 200, rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/settings' ) )->get_status() );
		$export = rest_do_request( new WP_REST_Request( 'GET', '/seostudio/v1/export' ) );
		$this->assertSame( 200, $export->get_status() );
		$this->assertArrayHasKey( 'settings', $export->get_data() );
	}

	public function test_activation_and_mode_changes_keep_post_content(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_name'    => 'unchanged-slug',
				'post_content' => '<p>Builder layout stays put.</p>',
			)
		);
		$before  = array(
			get_post_field( 'post_content', $post_id ),
			get_post_field( 'post_name', $post_id ),
		);

		Plugin::activate();
		$settings                   = Settings::all();
		$settings['mode']           = 'owner';
		$settings['schema_version'] = \SEOStudio\Core\Upgrader::CURRENT;
		update_option( Settings::OPTION, $settings, true );
		$settings['mode'] = 'analysis';
		update_option( Settings::OPTION, $settings, true );

		$this->assertSame( $before[0], get_post_field( 'post_content', $post_id ) );
		$this->assertSame( $before[1], get_post_field( 'post_name', $post_id ) );
	}

	public function test_uninstall_without_the_flag_keeps_tables(): void {
		global $wpdb;

		update_option( Settings::OPTION, array( 'schema_version' => \SEOStudio\Core\Upgrader::CURRENT, 'uninstall_delete_data' => false ), true );
		Uninstaller::run();
		$this->assertFalse( get_option( Settings::OPTION ) );
		$table = $wpdb->prefix . 'seostudio_pages';
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
	}

	public function test_uninstall_with_the_flag_drops_tables(): void {
		global $wpdb;

		// The test suite rewrites DROP TABLE into DROP TEMPORARY TABLE, which would not touch these real tables.
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		try {
			update_option( Settings::OPTION, array( 'schema_version' => \SEOStudio\Core\Upgrader::CURRENT, 'uninstall_delete_data' => true, 'mode' => 'analysis' ), true );
			Uninstaller::run();
			$table = $wpdb->prefix . 'seostudio_pages';
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
			Plugin::activate();
		} finally {
			add_filter( 'query', array( $this, '_create_temporary_tables' ) );
			add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		}
	}
}
