<?php
/**
 * Site audit route.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Core\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Phase 2 keeps the audit route, and Phase 3 runs the queue.
 */
final class AuditsController {
	public function register(): void {
		register_rest_route(
			'seostudio/v1',
			'/audits',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => static function (): bool {
						return Capabilities::canManage();
					},
				),
			)
		);

		register_rest_route(
			'seostudio/v1',
			'/audits/(?P<run_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => static function (): bool {
						return Capabilities::canManage();
					},
				),
			)
		);
	}

	public function create( WP_REST_Request $request ): WP_Error {
		unset( $request );
		return $this->unavailable();
	}

	public function show( WP_REST_Request $request ): WP_Error {
		unset( $request );
		return $this->unavailable();
	}

	private function unavailable(): WP_Error {
		return Responses::error(
			'seostudio_not_available',
			__( 'The site audit queue is not available in this phase.', 'seo-studio' ),
			501
		);
	}
}
