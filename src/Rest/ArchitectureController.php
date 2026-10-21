<?php
/**
 * Site architecture route.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Architecture\SiteSurvey;
use SEOStudio\Core\Capabilities;
use SEOStudio\Core\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Returns the internal-link report. The report does not edit content or request URLs.
 */
final class ArchitectureController {
	public function register(): void {
		register_rest_route(
			'seostudio/v1',
			'/architecture',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'show' ),
				'permission_callback' => static function (): bool {
					return Capabilities::canManage();
				},
			)
		);

		register_rest_route(
			'seostudio/v1',
			'/architecture/aliases',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'trust' ),
				'permission_callback' => static function (): bool {
					return Capabilities::canManage();
				},
				'args'                => array(
					'host'  => array(
						'type'              => 'string',
						'sanitize_callback' => static function ( mixed $value ): string {
							return strtolower( trim( (string) $value ) );
						},
					),
					'trust' => array(
						'type' => 'boolean',
					),
				),
			)
		);
	}

	public function show( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return Responses::json( ( new SiteSurvey() )->report() );
	}

	/**
	 * Trust or forget one exact hostname. Editors and authors are refused.
	 */
	public function trust( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$nonce = $request->get_header( 'x_wp_nonce' );
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return Responses::error( 'rest_cookie_invalid_nonce', __( 'The link expired. Reload Zoop and try again.', 'seo-studio' ), 403 );
		}
		$host = strtolower( trim( (string) $request->get_param( 'host' ) ) );
		if ( '' === $host || null === $request->get_param( 'trust' ) ) {
			return Responses::error( 'seostudio_invalid_host', __( 'Enter a hostname, with a port only when the site uses one.', 'seo-studio' ), 400, 'host' );
		}
		if ( ! Settings::isHostAlias( $host ) ) {
			return Responses::error( 'seostudio_invalid_host', __( 'Enter a hostname, with a port only when the site uses one.', 'seo-studio' ), 400, 'host' );
		}
		$trust = $request->get_param( 'trust' );
		$trust = true === $trust || 1 === $trust || '1' === $trust || 'true' === $trust;
		if ( ! Settings::trustHost( $host, $trust ) ) {
			return Responses::error( 'seostudio_invalid_host', __( 'That hostname could not be saved.', 'seo-studio' ), 400, 'host' );
		}
		return Responses::json( ( new SiteSurvey() )->report() );
	}
}
