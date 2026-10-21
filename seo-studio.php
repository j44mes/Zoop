<?php
/**
 * Plugin Name: Zoop
 * Description: On-page SEO scoring for WordPress, with an editor panel and dashboard.
 * Version: 0.6.0-beta.1
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

define( 'SEOSTUDIO_VERSION', '0.6.0-beta.1' );
define( 'SEOSTUDIO_FILE', __FILE__ );
define( 'SEOSTUDIO_DIR', plugin_dir_path( __FILE__ ) );

$seostudio_build_file = SEOSTUDIO_DIR . 'seo-studio-build.php';
if ( is_readable( $seostudio_build_file ) ) {
	$seostudio_build = include $seostudio_build_file;
	if ( is_array( $seostudio_build ) && isset( $seostudio_build['commit'] ) && is_string( $seostudio_build['commit'] ) && 1 === preg_match( '/\A[0-9a-f]{7,40}\z/', $seostudio_build['commit'] ) ) {
		define( 'SEOSTUDIO_BUILD', substr( $seostudio_build['commit'], 0, 7 ) );
	}
}
if ( ! defined( 'SEOSTUDIO_BUILD' ) ) {
	define( 'SEOSTUDIO_BUILD', '' );
}

$seostudio_autoload = SEOSTUDIO_DIR . 'vendor/autoload.php';
if ( is_readable( $seostudio_autoload ) ) {
	require_once $seostudio_autoload;
}

$seostudio_scheduler = SEOSTUDIO_DIR . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
if ( is_readable( $seostudio_scheduler ) ) {
	require_once $seostudio_scheduler;
}

if ( ! class_exists( \SEOStudio\Core\Plugin::class ) ) {
	return;
}

\SEOStudio\Core\Plugin::boot();
