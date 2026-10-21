<?php
/**
 * Cannibalisation route.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Core\Capabilities;
use SEOStudio\Topics\OverlapReport;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Returns overlap levels. It does not merge, redirect, or edit pages.
 */
final class OverlapController {
	public function register(): void {
		register_rest_route(
			'seostudio/v1',
			'/overlap',
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
		return Responses::json( ( new OverlapReport() )->report() );
	}
}
