<?php
/**
 * Admin menu.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Admin;

/**
 * Adds the Zoop overview screen.
 */
final class Menu {
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( Assets::class, 'dashboard' ) );
		add_action( 'admin_enqueue_scripts', array( Assets::class, 'classic' ) );
		add_action( 'enqueue_block_editor_assets', array( Assets::class, 'editor' ) );
		add_action( 'add_meta_boxes', array( MetaBox::class, 'register' ), 10, 2 );
		add_filter( 'use_block_editor_for_post', array( MetaBox::class, 'preferClassic' ), 100, 2 );
		add_filter( 'use_block_editor_for_post_type', array( MetaBox::class, 'preferClassicType' ), 100, 2 );
	}

	public static function menu(): void {
		add_menu_page(
			__( 'Zoop', 'seo-studio' ),
			__( 'Zoop', 'seo-studio' ),
			'edit_posts',
			'seostudio',
			array( self::class, 'render' ),
			'dashicons-chart-bar',
			58
		);
	}

	public static function render(): void {
		echo '<div class="wrap"><div id="seostudio-dashboard"></div></div>';
	}
}
