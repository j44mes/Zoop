<?php
/**
 * Settings and detector routes.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Core\Capabilities;
use SEOStudio\Core\Settings;
use SEOStudio\Core\VersionIdentity;
use SEOStudio\Integrations\Detector;
use SEOStudio\Output\HeadOutput;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Reads and writes seostudio_settings. Owner mode is refused while another SEO plugin is active.
 */
final class SettingsController {
	public function register(): void {
		register_rest_route(
			'seostudio/v1',
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'canManage' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'canManage' ),
				),
			)
		);

		register_rest_route(
			'seostudio/v1',
			'/detect',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'detect' ),
					'permission_callback' => array( $this, 'canManage' ),
				),
			)
		);
	}

	public function canManage(): bool {
		return Capabilities::canManage();
	}

	public function show(): WP_REST_Response {
		return Responses::json( $this->payload() );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$active = ( new Detector() )->active();
		if ( isset( $params['mode'] ) && 'owner' === $params['mode'] && array() !== $active ) {
			return new WP_Error(
				'seostudio_conflict',
				__( 'Owner mode stays off while another SEO plugin is active.', 'seo-studio' ),
				array(
					'status'           => 409,
					'detected_plugins' => $active,
				)
			);
		}

		$clean = Settings::sanitize( array_merge( Settings::all(), $params ) );
		update_option( Settings::OPTION, $clean, true );
		return Responses::json( $this->payload() );
	}

	public function detect(): WP_REST_Response {
		$detector = new Detector();
		$plugins  = array();
		foreach ( $detector->catalogue() as $plugin ) {
			$version   = '';
			$is_active = false;
			foreach ( $detector->active() as $active ) {
				if ( $active['slug'] === $plugin['slug'] ) {
					$version   = $active['version'];
					$is_active = true;
				}
			}
			$plugins[] = array(
				'slug'    => $plugin['slug'],
				'name'    => $plugin['name'],
				'version' => $version,
				'active'  => $is_active,
			);
		}

		return Responses::json(
			array(
				'plugins'         => $plugins,
				'theme_title_tag' => current_theme_supports( 'title-tag' ),
				'publishing'      => ( new HeadOutput() )->publishing(),
			)
		);
	}

	/**
	 * Settings plus whether head output is currently publishing.
	 *
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		$settings                  = Settings::all();
		$settings['publishing']    = ( new HeadOutput() )->publishing();
		$active                    = ( new Detector() )->first();
		$settings['detected']      = null === $active ? null : $active;
		$settings['version_label'] = VersionIdentity::label();
		return $settings;
	}
}
