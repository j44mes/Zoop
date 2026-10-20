<?php
/**
 * Boots the WordPress test suite and Zoop.
 *
 * @package SEOStudio
 */

$candidates = array(
	getenv( 'WP_TESTS_DIR' ),
	getenv( 'WP_PHPUNIT__DIR' ),
	'/wordpress-phpunit',
	'/tmp/wordpress-tests-lib',
);

$tests_dir = '';
foreach ( $candidates as $candidate ) {
	if ( is_string( $candidate ) && is_readable( $candidate . '/includes/functions.php' ) ) {
		$tests_dir = $candidate;
		break;
	}
}

if ( '' === $tests_dir ) {
	fwrite( STDERR, "WordPress test library not found. Start it with wp-env.\n" );
	exit( 1 );
}

// Enables Detector::force() and the seostudio_live_model seam. Never define this on a site.
if ( ! defined( 'SEOSTUDIO_TESTING' ) ) {
	define( 'SEOSTUDIO_TESTING', true );
}

if ( ! defined( 'SEOSTUDIO_JOBS_SYNC' ) ) {
	define( 'SEOSTUDIO_JOBS_SYNC', true );
}

// The suite stands in for a local site. The localhost fetch exception still
// requires SEOSTUDIO_LOCAL_OBSERVE, which individual tests define explicitly.
if ( ! defined( 'WP_ENVIRONMENT_TYPE' ) ) {
	define( 'WP_ENVIRONMENT_TYPE', 'local' );
}

require dirname( __DIR__, 3 ) . '/vendor/autoload.php';
require $tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__, 3 ) . '/seo-studio.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';
