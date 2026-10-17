<?php
/**
 * Admin script registration.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Admin;

/**
 * Enqueues the built dashboard, sidebar, and classic meta box.
 */
final class Assets {
	public static function dashboard( string $hook ): void {
		if ( 'toplevel_page_seostudio' !== $hook ) {
			return;
		}
		self::enqueue( 'seostudio-dashboard', 'dashboard', 0, false );
	}

	public static function editor(): void {
		$post_id = absint( (string) filter_input( INPUT_GET, 'post', FILTER_SANITIZE_NUMBER_INT ) );
		self::enqueue( 'seostudio-editor', 'editor', $post_id, true );
	}

	public static function classic( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ( function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( $screen->post_type ) ) ) {
			return;
		}
		$post_id = absint( (string) filter_input( INPUT_GET, 'post', FILTER_SANITIZE_NUMBER_INT ) );
		self::enqueue( 'seostudio-metabox', 'metabox', $post_id, false );
	}

	private static function enqueue( string $handle, string $name, int $post_id, bool $block_editor ): void {
		$root       = plugin_dir_path( SEOSTUDIO_FILE );
		$asset_path = $root . "assets/build/{$name}.asset.php";
		if ( ! is_readable( $asset_path ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-warning"><p>' . esc_html__( 'Zoop admin assets are not built. Run npm run build in the plugin directory.', 'seo-studio' ) . '</p></div>';
				}
			);
			return;
		}

		$asset = include $asset_path;
		if ( ! is_array( $asset ) ) {
			return;
		}

		$dependencies = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();
		$version      = isset( $asset['version'] ) ? (string) $asset['version'] : SEOSTUDIO_VERSION;
		wp_enqueue_script(
			$handle,
			plugins_url( "assets/build/{$name}.js", SEOSTUDIO_FILE ),
			$dependencies,
			$version,
			true
		);
		wp_enqueue_style( 'wp-components' );

		$style = $root . 'assets/build/style-dashboard.css';
		if ( is_readable( $style ) ) {
			wp_enqueue_style( $handle, plugins_url( 'assets/build/style-dashboard.css', SEOSTUDIO_FILE ), array(), $version );
		}

		$permalink = $post_id > 0 ? get_permalink( $post_id ) : '';
		wp_add_inline_script(
			$handle,
			'window.seostudioEditor = ' . wp_json_encode(
				array(
					'postId'      => $post_id,
					'blockEditor' => $block_editor,
					'workerUrl'   => plugins_url( 'assets/build/worker.js', SEOSTUDIO_FILE ),
					'permalink'   => is_string( $permalink ) ? $permalink : '',
				)
			) . ';',
			'before'
		);
	}
}
