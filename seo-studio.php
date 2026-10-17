<?php
/**
 * Plugin Name: Zoop
 * Description: On-page SEO scoring for WordPress, with an editor panel and dashboard.
 * Version: 0.5.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Zoop
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: seo-studio
 *
 * @package SEOStudio
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'SEOSTUDIO_VERSION', '0.5.0' );
define( 'SEOSTUDIO_FILE', __FILE__ );
define( 'SEOSTUDIO_DIR', plugin_dir_path( __FILE__ ) );

$seostudio_autoload = SEOSTUDIO_DIR . 'vendor/autoload.php';
if ( is_readable( $seostudio_autoload ) ) {
	require_once $seostudio_autoload;
}

$seostudio_scheduler = SEOSTUDIO_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
if ( is_readable( $seostudio_scheduler ) ) {
	require_once $seostudio_scheduler;
}

if ( ! class_exists( \SEOStudio\Core\Plugin::class ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			echo '<div class="notice notice-error"><p>' . esc_html__( 'Zoop cannot start because its PHP dependencies are missing. Upload the release ZIP, which includes the vendor directory. A download of the source code does not run.', 'seo-studio' ) . '</p></div>';
		}
	);
	return;
}

\SEOStudio\Core\Plugin::boot();
