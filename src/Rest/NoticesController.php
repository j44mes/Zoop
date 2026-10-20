<?php
/**
 * Dashboard notice actions.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Core\UpgradeNotice;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Dismissal is stored on the user. It does not change the ruleset marker.
 */
final class NoticesController {
	public function register(): void {
		register_rest_route(
			'seostudio/v1',
			'/notices/ruleset-retirement/dismiss',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	public function dismiss(): WP_REST_Response {
		UpgradeNotice::dismiss();

		return Responses::json( array( 'dismissed' => true ) );
	}
}
