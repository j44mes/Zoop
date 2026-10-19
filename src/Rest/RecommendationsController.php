<?php
/**
 * Prioritised recommendations route.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Core\Capabilities;
use SEOStudio\Recommendations\PrioritiesReport;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Returns an ordered review list. It does not change content.
 */
final class RecommendationsController {
	public function register(): void {
		register_rest_route(
			'seostudio/v1',
			'/recommendations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'show' ),
				'permission_callback' => static function (): bool {
					return Capabilities::canManage();
				},
			)
		);
	}

	public function show( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return Responses::json( ( new PrioritiesReport() )->report() );
	}
}
