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
	}

	public function show( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return Responses::json( ( new SiteSurvey() )->report() );
	}
}
