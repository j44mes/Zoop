<?php
/**
 * Plugin bootstrap.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Core;

use SEOStudio\Cli\Commands;
use SEOStudio\Rest\KeyphrasesController;
use SEOStudio\Rest\PagesController;
use SEOStudio\Rest\PostSeoController;

/**
 * Wires hooks that do not depend on the admin UI.
 */
final class Plugin {
	public static function boot(): void {
		add_action( 'init', array( self::class, 'init' ) );
		add_action( 'rest_api_init', array( self::class, 'rest' ) );
		register_activation_hook( SEOSTUDIO_FILE, array( self::class, 'activate' ) );
	}

	public static function init(): void {
		load_plugin_textdomain( 'seo-studio', false, dirname( plugin_basename( SEOSTUDIO_FILE ) ) . '/languages' );
		self::registerMeta();
		Commands::register();
	}

	public static function rest(): void {
		( new PagesController() )->register();
		( new PostSeoController() )->register();
		( new KeyphrasesController() )->register();
	}

	public static function activate(): void {
		Upgrader::maybeUpgrade();
	}

	private static function registerMeta(): void {
		$keys = array(
			'_seostudio_focus_keyphrase'  => 'string',
			'_seostudio_title'            => 'string',
			'_seostudio_description'      => 'string',
			'_seostudio_canonical'        => 'string',
			'_seostudio_cornerstone'      => 'boolean',
			'_seostudio_content_selector' => 'string',
		);

		foreach ( $keys as $key => $type ) {
			register_post_meta(
				'',
				$key,
				array(
					'type'              => $type,
					'single'            => true,
					'show_in_rest'      => true,
					'auth_callback'     => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
						unset( $allowed, $meta_key );
						return current_user_can( 'edit_post', $post_id );
					},
					'sanitize_callback' => 'boolean' === $type ? 'rest_sanitize_boolean' : 'sanitize_text_field',
				)
			);
		}
	}
}
