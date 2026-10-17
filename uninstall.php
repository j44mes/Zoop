<?php
/**
 * Removes scheduled work, and stored data only when the owner opted in.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$seostudio_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $seostudio_autoload ) ) {
	require_once $seostudio_autoload;
}

$seostudio_scheduler = __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
if ( is_readable( $seostudio_scheduler ) ) {
	require_once $seostudio_scheduler;
}

if ( ! class_exists( \SEOStudio\Core\Uninstaller::class ) ) {
	return;
}

\SEOStudio\Core\Uninstaller::run();
