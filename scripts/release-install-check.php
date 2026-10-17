<?php
/**
 * Checks used by scripts/verify-release-zip.sh.
 *
 * WordPress is already loaded. ZOOP_CHECK selects snapshot, seed, assert, or cleanup.
 * wp eval-file wraps this file, so it cannot declare strict types.
 *
 * @package SEOStudio
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this file with wp eval-file.\n" );
	exit( 1 );
}

$check = getenv( 'ZOOP_CHECK' ) ?: '';
$state = sys_get_temp_dir() . '/zoop-release-check.json';

/**
 * Print a message and stop the check.
 */
function zoop_release_fail( string $message ): void {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

/**
 * Read the JSON state file.
 *
 * @return array<string, mixed>
 */
function zoop_release_state( string $path ): array {
	if ( ! is_readable( $path ) ) {
		return array();
	}
	$decoded = json_decode( (string) file_get_contents( $path ), true );
	return is_array( $decoded ) ? $decoded : array();
}

/**
 * Persist the JSON state file.
 *
 * @param array<string, mixed> $state State to store.
 */
function zoop_release_write( string $path, array $state ): void {
	$encoded = json_encode( $state );
	if ( ! is_string( $encoded ) || false === file_put_contents( $path, $encoded ) ) {
		zoop_release_fail( 'Could not write the release-check state.' );
	}
}

/**
 * Rows and settings that an upgrade must keep.
 *
 * @return array<string, mixed>
 */
function zoop_release_snapshot(): array {
	global $wpdb;

	$pages = $wpdb->prefix . 'seostudio_pages';
	$scores = $wpdb->prefix . 'seostudio_scores';
	$page_rows = $wpdb->get_results( "SELECT id, object_id, onpage_score, onpage_denominator, focus_keyphrase FROM {$pages} ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$meta_rows = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_seostudio\\_%' ORDER BY meta_id", ARRAY_A );
	if ( ! is_array( $page_rows ) || ! is_array( $meta_rows ) ) {
		zoop_release_fail( 'Could not read the existing Zoop rows.' );
	}

	return array(
		'settings'    => get_option( 'seostudio_settings' ),
		'pages'       => $page_rows,
		'meta'        => $meta_rows,
		'score_count' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$scores}" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
}

/**
 * Confirm the installed plugin is the packaged Zoop build.
 */
function zoop_release_plugin(): void {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$expected = getenv( 'ZOOP_EXPECT_VERSION' ) ?: '';
	$file     = WP_PLUGIN_DIR . '/zoop/seo-studio.php';
	if ( ! is_readable( $file ) ) {
		zoop_release_fail( 'zoop/seo-studio.php is not installed.' );
	}
	$headers = get_plugin_data( $file, false, false );
	$version = isset( $headers['Version'] ) ? (string) $headers['Version'] : '';
	if ( '' === $expected || $version !== $expected ) {
		zoop_release_fail( "Installed version is {$version}; expected {$expected}." );
	}
	if ( ! is_plugin_active( 'zoop/seo-studio.php' ) ) {
		zoop_release_fail( 'Zoop is not active.' );
	}
	if ( 'Zoop' !== ( $headers['Name'] ?? '' ) ) {
		zoop_release_fail( 'The installed plugin name is not Zoop.' );
	}

	$dashboard = WP_PLUGIN_DIR . '/zoop/assets/build/dashboard.js';
	$contents  = file_get_contents( $dashboard );
	if ( ! is_string( $contents ) || ! str_contains( $contents, 'Zoop' ) || ! str_contains( $contents, 'seostudio-dashboard' ) ) {
		zoop_release_fail( 'The installed dashboard bundle is missing or does not render Zoop.' );
	}

	require_once ABSPATH . 'wp-admin/includes/admin.php';
	wp_set_current_user( 1 );
	set_current_screen( 'toplevel_page_seostudio' );
	do_action( 'admin_menu' );
	$found = false;
	foreach ( $GLOBALS['menu'] as $item ) {
		if ( isset( $item[2] ) && 'seostudio' === $item[2] && isset( $item[0] ) && str_contains( (string) $item[0], 'Zoop' ) ) {
			$found = true;
		}
	}
	if ( ! $found ) {
		zoop_release_fail( 'The Zoop menu is not registered.' );
	}

	do_action( 'admin_enqueue_scripts', 'toplevel_page_seostudio' );
	$script = wp_scripts()->registered['seostudio-dashboard'] ?? null;
	if ( ! $script || ! str_contains( (string) $script->src, 'assets/build/dashboard.js' ) ) {
		zoop_release_fail( 'The dashboard screen does not enqueue assets/build/dashboard.js.' );
	}

	do_action( 'rest_api_init' );
	$routes = rest_get_server()->get_routes();
	if ( ! isset( $routes['/seostudio/v1'] ) && ! isset( $routes['/seostudio/v1/pages'] ) ) {
		zoop_release_fail( 'The seostudio/v1 routes are not registered.' );
	}
}

switch ( $check ) {
	case 'snapshot':
		zoop_release_write( $state, array( 'snapshot' => zoop_release_snapshot() ) );
		echo "snapshot written\n";
		break;

	case 'seed':
		$stored   = zoop_release_state( $state );
		$settings = get_option( 'seostudio_settings' );
		if ( ! is_array( $settings ) ) {
			zoop_release_fail( 'Zoop settings were not created on activation.' );
		}
		$previous                  = $settings;
		$settings['home_description'] = 'release-canary-description';
		update_option( 'seostudio_settings', $settings, true );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Release package canary',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			zoop_release_fail( $post_id->get_error_message() );
		}
		update_post_meta( $post_id, '_seostudio_focus_keyphrase', 'release canary' );

		global $wpdb;
		$url = home_url( '/release-canary/' );
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'seostudio_pages',
			array(
				'object_type'         => 'page',
				'object_id'           => $post_id,
				'url'                 => $url,
				'url_hash'            => md5( $url ),
				'input_hash'          => md5( 'release-canary' ),
				'render_mode'         => 'content',
				'focus_keyphrase'     => 'release canary',
				'focus_hash'          => md5( 'release canary' ),
				'onpage_score'        => 28,
				'onpage_denominator'  => 94,
				'onpage_rating'       => 'red',
			)
		);
		if ( ! $inserted ) {
			zoop_release_fail( 'Could not store the canary page score.' );
		}
		$page_id = (int) $wpdb->insert_id;
		$scored  = $wpdb->insert(
			$wpdb->prefix . 'seostudio_scores',
			array(
				'page_id'         => $page_id,
				'keyphrase'       => 'release canary',
				'keyphrase_hash'  => md5( 'release canary' ),
				'engine'          => 'onpage',
				'score'           => 28,
				'denominator'     => 94,
				'rating'          => 'red',
				'plugin_version'  => defined( 'SEOSTUDIO_VERSION' ) ? SEOSTUDIO_VERSION : '',
				'created_at'      => current_time( 'mysql' ),
			)
		);
		if ( ! $scored ) {
			zoop_release_fail( 'Could not store the canary score row.' );
		}

		$stored['previous_settings'] = $previous;
		$stored['canary']            = array(
			'post_id' => (int) $post_id,
			'page_id' => $page_id,
			'url'     => $url,
		);
		zoop_release_write( $state, $stored );
		echo "canary {$post_id}\n";
		break;

	case 'assert':
		zoop_release_plugin();
		$stored = zoop_release_state( $state );
		$canary = $stored['canary'] ?? null;
		if ( ! is_array( $canary ) ) {
			zoop_release_fail( 'The canary was not recorded before the second install.' );
		}

		$settings = get_option( 'seostudio_settings' );
		if ( ! is_array( $settings ) || 'release-canary-description' !== ( $settings['home_description'] ?? '' ) ) {
			zoop_release_fail( 'The settings canary did not survive the second install.' );
		}
		if ( false !== ( $settings['uninstall_delete_data'] ?? false ) ) {
			zoop_release_fail( 'The second install turned on uninstall_delete_data.' );
		}
		if ( 'release canary' !== get_post_meta( (int) $canary['post_id'], '_seostudio_focus_keyphrase', true ) ) {
			zoop_release_fail( 'The canary keyphrase meta did not survive the second install.' );
		}

		global $wpdb;
		$page = $wpdb->get_row(
			$wpdb->prepare( "SELECT onpage_score, onpage_denominator, focus_keyphrase FROM {$wpdb->prefix}seostudio_pages WHERE id = %d", (int) $canary['page_id'] ),
			ARRAY_A
		);
		if ( ! is_array( $page ) || 28 !== (int) $page['onpage_score'] || 94 !== (int) $page['onpage_denominator'] ) {
			zoop_release_fail( 'The canary on-page score did not survive the second install.' );
		}
		$score = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}seostudio_scores WHERE page_id = %d AND score = 28", (int) $canary['page_id'] ) );
		if ( 1 !== $score ) {
			zoop_release_fail( 'The canary score row did not survive the second install.' );
		}

		if ( isset( $stored['snapshot'] ) && is_array( $stored['snapshot'] ) ) {
			$current = zoop_release_snapshot();
			$before  = $stored['snapshot'];
			$pages   = array_values(
				array_filter(
					$current['pages'],
					static function ( $row ) use ( $canary ): bool {
						return (int) $row['id'] !== (int) $canary['page_id'];
					}
				)
			);
			$meta    = array_values(
				array_filter(
					$current['meta'],
					static function ( $row ) use ( $canary ): bool {
						return (int) $row['post_id'] !== (int) $canary['post_id'];
					}
				)
			);
			$normalize = static function ( $value ) {
				$encoded = wp_json_encode( $value );
				if ( ! is_string( $encoded ) ) {
					zoop_release_fail( 'Could not compare stored Zoop data.' );
				}
				return json_decode( $encoded, true );
			};
			if ( $normalize( $pages ) !== $normalize( $before['pages'] ) || $normalize( $meta ) !== $normalize( $before['meta'] ) ) {
				zoop_release_fail( 'Existing Zoop rows or post meta changed during the reinstall.' );
			}
			if ( (int) $current['score_count'] !== (int) $before['score_count'] + 1 ) {
				zoop_release_fail( 'Existing score rows were not all kept.' );
			}
			$before_settings = $before['settings'];
			$now_settings    = $settings;
			if ( is_array( $before_settings ) ) {
				$now_settings['home_description'] = $before_settings['home_description'] ?? '';
			}
			if ( $normalize( $now_settings ) !== $normalize( $before_settings ) ) {
				zoop_release_fail( 'Existing Zoop settings changed during the reinstall.' );
			}
		}
		echo "release package verified\n";
		break;

	case 'cleanup':
		$stored = zoop_release_state( $state );
		$canary = $stored['canary'] ?? null;
		if ( is_array( $canary ) ) {
			wp_delete_post( (int) $canary['post_id'], true );
			global $wpdb;
			$wpdb->delete( $wpdb->prefix . 'seostudio_scores', array( 'page_id' => (int) $canary['page_id'] ) );
			$wpdb->delete( $wpdb->prefix . 'seostudio_pages', array( 'id' => (int) $canary['page_id'] ) );
		}
		if ( isset( $stored['previous_settings'] ) && is_array( $stored['previous_settings'] ) ) {
			update_option( 'seostudio_settings', $stored['previous_settings'], true );
		}
		if ( is_file( $state ) ) {
			unlink( $state );
		}
		echo "cleanup done\n";
		break;

	default:
		zoop_release_fail( 'Set ZOOP_CHECK to snapshot, seed, assert, or cleanup.' );
}
