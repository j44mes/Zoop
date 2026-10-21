<?php
/**
 * Plugin bootstrap.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Core;

use SEOStudio\Admin\Menu;
use SEOStudio\Admin\Notices;
use SEOStudio\Appearance\EditorMeta;
use SEOStudio\Cli\Commands;
use SEOStudio\Jobs\Scheduler;
use SEOStudio\Output\HeadOutput;
use SEOStudio\Rest\AppearanceController;
use SEOStudio\Rest\ArchitectureController;
use SEOStudio\Rest\AuditsController;
use SEOStudio\Rest\CapabilitiesController;
use SEOStudio\Rest\ContentScoresController;
use SEOStudio\Rest\KeyphrasesController;
use SEOStudio\Rest\MigrationsController;
use SEOStudio\Rest\ModelController;
use SEOStudio\Rest\OverlapController;
use SEOStudio\Rest\NoticesController;
use SEOStudio\Rest\PagesController;
use SEOStudio\Rest\PostSeoController;
use SEOStudio\Rest\RecommendationsController;
use SEOStudio\Rest\Responses;
use SEOStudio\Rest\SearchConsoleController;
use SEOStudio\Rest\SettingsController;
use SEOStudio\Rest\ToolsController;
use SEOStudio\Rest\TopicController;
use SEOStudio\Storage\KeyphraseHooks;
use SEOStudio\Storage\PublishedFreshness;

/**
 * Wires hooks that do not depend on the admin UI.
 */
final class Plugin {
	public static function boot(): void {
		add_action( 'init', array( self::class, 'init' ) );
		add_action( 'rest_api_init', array( self::class, 'rest' ) );
		add_filter( 'rest_request_after_callbacks', array( Responses::class, 'documentForbidden' ), 10, 3 );
		register_activation_hook( SEOSTUDIO_FILE, array( self::class, 'activate' ) );
		register_deactivation_hook( SEOSTUDIO_FILE, array( self::class, 'deactivate' ) );
	}

	public static function init(): void {
		Upgrader::maybeUpgrade();
		load_plugin_textdomain( 'seo-studio', false, dirname( plugin_basename( SEOSTUDIO_FILE ) ) . '/languages' );
		EditorMeta::register();
		self::registerMeta();
		KeyphraseHooks::register();
		PublishedFreshness::register();
		Commands::register();
		Menu::register();
		Notices::register();
		Scheduler::boot();
		HeadOutput::register();
	}

	public static function deactivate(): void {
		Scheduler::unschedule();
	}

	public static function rest(): void {
		static $registered = false;

		PostSeoController::registerValidationFilter();
		if ( $registered ) {
			return;
		}
		$registered = true;

		( new PagesController() )->register();
		( new CapabilitiesController() )->register();
		( new PostSeoController() )->register();
		( new AppearanceController() )->register();
		( new KeyphrasesController() )->register();
		( new ModelController() )->register();
		( new ContentScoresController() )->register();
		( new AuditsController() )->register();
		( new ArchitectureController() )->register();
		( new TopicController() )->register();
		( new OverlapController() )->register();
		( new SearchConsoleController() )->register();
		( new RecommendationsController() )->register();
		( new SettingsController() )->register();
		( new MigrationsController() )->register();
		( new ToolsController() )->register();
		( new NoticesController() )->register();
	}

	public static function activate(): void {
		Upgrader::maybeUpgrade();
	}

	private static function registerMeta(): void {
		$keys = array(
			'_seostudio_title_proposal'                  => 'string',
			'_seostudio_description_proposal'            => 'string',
			'_seostudio_appearance_revision'             => 'string',
			'_seostudio_appearance_saved'                => 'string',
			'_seostudio_suggestion_baseline_title'       => 'string',
			'_seostudio_suggestion_baseline_description' => 'string',
			'_seostudio_suggestion_baseline_saved'       => 'string',
			'_seostudio_title_previous'                  => 'string',
			'_seostudio_description_previous'            => 'string',
			'_seostudio_appearance_previous_saved'       => 'string',
			'_seostudio_appearance_previous_target'      => 'string',
			'_seostudio_og_image'                        => 'integer',
			'_seostudio_cornerstone'                     => 'boolean',
			'_seostudio_content_selector'                => 'string',
			'_seostudio_primary_topic'                   => 'string',
			'_seostudio_primary_topic_origin'            => 'string',
			'_seostudio_page_role'                       => 'string',
			'_seostudio_search_intent'                   => 'string',
			'_seostudio_parent_topic'                    => 'string',
			'_seostudio_supporting_entities'             => 'array',
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
