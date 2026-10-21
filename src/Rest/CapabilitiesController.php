<?php
/**
 * What the current user may open in Zoop.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Core\Capabilities;
use SEOStudio\Core\LegacyScoreNotice;
use SEOStudio\Core\UpgradeNotice;
use SEOStudio\Core\VersionIdentity;
use SEOStudio\Integrations\Detector;
use SEOStudio\Output\HeadOutput;
use WP_REST_Server;

/**
 * A small manifest so editors are not sent to administrator routes.
 */
final class CapabilitiesController {
	public function register(): void {
		register_rest_route(
			'seostudio/v1',
			'/capabilities',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'show' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	public function show(): \WP_REST_Response {
		$active = ( new Detector() )->first();
		return Responses::json(
			array(
				'manage'         => Capabilities::canManage(),
				'edit_posts'     => current_user_can( 'edit_posts' ),
				'publishing'     => ( new HeadOutput() )->publishing(),
				'detected_name'  => is_array( $active ) ? $active['name'] : '',
				'version_label'  => VersionIdentity::label(),
				'upgrade_notice' => UpgradeNotice::payload(),
				'legacy_notice'  => LegacyScoreNotice::payload(),
			)
		);
	}
}
