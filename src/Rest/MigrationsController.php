<?php
/**
 * One-way import from another SEO plugin.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Core\Capabilities;
use SEOStudio\Integrations\Importers\Catalogue;
use SEOStudio\Jobs\ImportBatch;
use InvalidArgumentException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Preview counts, then a batched copy. The source plugin is never deactivated.
 */
final class MigrationsController {
	public function register(): void {
		$args = array(
			'source' => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			),
		);

		register_rest_route(
			'seostudio/v1',
			'/migrations/preview',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'preview' ),
					'permission_callback' => array( $this, 'canManage' ),
					'args'                => $args,
				),
			)
		);

		register_rest_route(
			'seostudio/v1',
			'/migrations',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'canManage' ),
					'args'                => $args,
				),
			)
		);
	}

	public function canManage(): bool {
		return Capabilities::canManage();
	}

	public function preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		try {
			$importer = Catalogue::make( (string) $request->get_param( 'source' ) );
		} catch ( InvalidArgumentException $exception ) {
			return Responses::error( 'seostudio_validation', $exception->getMessage(), 400, 'source' );
		}

		return Responses::json(
			array(
				'source' => $importer->source(),
				'counts' => $importer->counts(),
			)
		);
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$source = (string) $request->get_param( 'source' );
		try {
			Catalogue::make( $source );
		} catch ( InvalidArgumentException $exception ) {
			return Responses::error( 'seostudio_validation', $exception->getMessage(), 400, 'source' );
		}

		$run_id = ( new ImportBatch() )->start( $source );
		return Responses::json( array( 'run_id' => $run_id ), 202 );
	}
}
