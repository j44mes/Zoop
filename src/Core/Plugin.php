<?php
/**
 * Plugin bootstrap.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Core;

use SEOStudio\Admin\Menu;
use SEOStudio\Cli\Commands;
use SEOStudio\Rest\AuditsController;
use SEOStudio\Rest\ContentScoresController;
use SEOStudio\Rest\KeyphrasesController;
use SEOStudio\Rest\ModelController;
use SEOStudio\Rest\PagesController;
use SEOStudio\Rest\PostSeoController;
use SEOStudio\Storage\KeyphraseHooks;

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
		Upgrader::maybeUpgrade();
		load_plugin_textdomain( 'seo-studio', false, dirname( plugin_basename( SEOSTUDIO_FILE ) ) . '/languages' );
		self::registerMeta();
		KeyphraseHooks::register();
		Commands::register();
		Menu::register();
	}

	public static function rest(): void {
		static $registered = false;

		PostSeoController::registerValidationFilter();
		if ( $registered ) {
			return;
		}
		$registered = true;

		( new PagesController() )->register();
		( new PostSeoController() )->register();
		( new KeyphrasesController() )->register();
		( new ModelController() )->register();
		( new ContentScoresController() )->register();
		( new AuditsController() )->register();
	}

	public static function activate(): void {
		Upgrader::maybeUpgrade();
	}

	private static function registerMeta(): void {
		$keys = array(
			'_seostudio_focus_keyphrase'    => 'string',
			'_seostudio_related_keyphrases' => 'array',
			'_seostudio_synonyms'           => 'array',
			'_seostudio_title'              => 'string',
			'_seostudio_description'        => 'string',
			'_seostudio_canonical'          => 'string',
			'_seostudio_robots'             => 'array',
			'_seostudio_og_title'           => 'string',
			'_seostudio_og_description'     => 'string',
			'_seostudio_og_image'           => 'integer',
			'_seostudio_cornerstone'        => 'boolean',
			'_seostudio_content_selector'   => 'string',
		);

		foreach ( $keys as $key => $type ) {
			register_post_meta(
				'',
				$key,
				array(
					'type'         => $type,
					'single'       => true,
					'show_in_rest' => false,
				)
			);
		}
	}
}
