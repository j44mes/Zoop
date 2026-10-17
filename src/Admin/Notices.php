<?php
/**
 * Admin warnings for Owner mode.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Admin;

use SEOStudio\Core\Settings;
use SEOStudio\Integrations\Detector;
use SEOStudio\Jobs\Scheduler;

/**
 * The interlock and the missing title-tag support each get one sentence.
 */
final class Notices {
	public static function register(): void {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;

		add_action( 'admin_notices', array( self::class, 'render' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = Settings::all();
		$active   = ( new Detector() )->first();
		if ( 'owner' === (string) ( $settings['mode'] ?? '' ) && null !== $active ) {
			echo '<div class="notice notice-warning"><p>' . esc_html(
				sprintf(
					/* translators: %s: the other SEO plugin's name */
					__( 'Zoop has suspended its head output because %s is active. Nothing was deleted.', 'seo-studio' ),
					$active['name']
				)
			) . '</p></div>';
		}

		if ( 'owner' === (string) ( $settings['mode'] ?? '' ) && ! current_theme_supports( 'title-tag' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'The active theme does not support the title-tag feature, so Zoop will not print a second title.', 'seo-studio' ) . '</p></div>';
		}

		if ( Scheduler::cronDisabled() ) {
			echo '<div class="notice notice-info"><p>' . esc_html(
				sprintf(
					/* translators: %s: WP-CLI command */
					__( 'WP-Cron is disabled. Add this cron line so queued audits run: %s', 'seo-studio' ),
					Scheduler::cronCommand()
				)
			) . '</p></div>';
		}
	}
}
