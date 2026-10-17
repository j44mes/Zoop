<?php
/**
 * Export and import of Zoop's own data.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Core\Capabilities;
use SEOStudio\Core\Settings;
use SEOStudio\Core\Uninstaller;
use SEOStudio\Integrations\Detector;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Export writes settings and meta. Import restores those and never writes scores.
 */
final class ToolsController {
	public function register(): void {
		register_rest_route(
			'seostudio/v1',
			'/export',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'export' ),
					'permission_callback' => array( $this, 'canManage' ),
				),
			)
		);

		register_rest_route(
			'seostudio/v1',
			'/import',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import' ),
					'permission_callback' => array( $this, 'canManage' ),
				),
			)
		);
	}

	public function canManage(): bool {
		return Capabilities::canManage();
	}

	public function export(): WP_REST_Response {
		global $wpdb;

		$keys = Uninstaller::metaKeys();
		$list = "'" . implode( "','", $keys ) . "'";
		$rows = $wpdb->get_results(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ({$list})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Keys are the plugin's own meta keys.
			ARRAY_A
		);

		return Responses::json(
			array(
				'settings' => Settings::all(),
				'meta'     => is_array( $rows ) ? $rows : array(),
			)
		);
	}

	public function import( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return Responses::error( 'seostudio_validation', __( 'The import body must be a JSON object.', 'seo-studio' ), 400 );
		}

		if ( isset( $params['settings'] ) && is_array( $params['settings'] ) ) {
			$clean = Settings::sanitize( $params['settings'] );
			if ( 'owner' === $clean['mode'] && null !== ( new Detector() )->first() ) {
				$clean['mode'] = 'analysis';
			}
			update_option( Settings::OPTION, $clean, true );
		}

		$allowed = array_flip( Uninstaller::metaKeys() );
		$meta    = isset( $params['meta'] ) && is_array( $params['meta'] ) ? $params['meta'] : array();
		$written = 0;
		foreach ( $meta as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = (string) ( $row['meta_key'] ?? '' );
			if ( ! isset( $allowed[ $key ] ) ) {
				continue;
			}
			$value = $row['meta_value'] ?? '';
			if ( is_string( $value ) ) {
				$value = maybe_unserialize( $value );
			}
			update_post_meta( (int) ( $row['post_id'] ?? 0 ), $key, $value );
			++$written;
		}

		return Responses::json(
			array(
				'meta_written' => $written,
				'settings'     => Settings::all(),
			)
		);
	}
}
