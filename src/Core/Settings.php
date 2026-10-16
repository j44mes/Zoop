<?php
/**
 * Plugin settings.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Core;

/**
 * One versioned settings option.
 */
final class Settings {
	public const OPTION = 'seostudio_settings';

	/**
	 * Default settings stored on first save.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'schema_version'        => 1,
			'mode'                  => 'analysis',
			'site_title_separator'  => '–',
			'home_title'            => '%%sitename%% %%sep%% %%tagline%%',
			'home_description'      => '',
			'default_robots'        => array(
				'index'             => true,
				'follow'            => true,
				'max_snippet'       => null,
				'max_image_preview' => null,
			),
			'og_default_image'      => 0,
			'content_selectors'     => array(),
			'batch_size'            => 25,
			'batch_pause_ms'        => 0,
			'function_word_locale'  => 'en',
			'uninstall_delete_data' => false,
		);
	}

	/**
	 * Return the saved settings merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Sanitise a settings payload before it is stored.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $input ): array {
		$clean                          = self::defaults();
		$clean['mode']                  = ( isset( $input['mode'] ) && 'owner' === $input['mode'] ) ? 'owner' : 'analysis';
		$clean['site_title_separator']  = isset( $input['site_title_separator'] )
			? sanitize_text_field( (string) $input['site_title_separator'] )
			: $clean['site_title_separator'];
		$clean['home_title']            = isset( $input['home_title'] ) ? sanitize_text_field( (string) $input['home_title'] ) : '';
		$clean['home_description']      = isset( $input['home_description'] ) ? sanitize_textarea_field( (string) $input['home_description'] ) : '';
		$clean['og_default_image']      = isset( $input['og_default_image'] ) ? absint( $input['og_default_image'] ) : 0;
		$clean['batch_size']            = isset( $input['batch_size'] ) ? max( 1, min( 100, absint( $input['batch_size'] ) ) ) : 25;
		$clean['batch_pause_ms']        = isset( $input['batch_pause_ms'] ) ? max( 0, absint( $input['batch_pause_ms'] ) ) : 0;
		$clean['function_word_locale']  = isset( $input['function_word_locale'] )
			? sanitize_key( (string) $input['function_word_locale'] )
			: 'en';
		$clean['uninstall_delete_data'] = ! empty( $input['uninstall_delete_data'] );
		$clean['schema_version']        = 1;

		if ( isset( $input['content_selectors'] ) && is_array( $input['content_selectors'] ) ) {
			$selectors = array();
			foreach ( $input['content_selectors'] as $type => $selector ) {
				$selectors[ sanitize_key( (string) $type ) ] = sanitize_text_field( (string) $selector );
			}
			$clean['content_selectors'] = $selectors;
		}

		return $clean;
	}
}
