<?php
/**
 * Search Console import route.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Core\Capabilities;
use SEOStudio\Search\CsvImport;
use SEOStudio\Search\SearchRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Imports a performance export and reads it back. There is no OAuth connection.
 */
final class SearchConsoleController {
	public function register(): void {
		register_rest_route(
			'seostudio/v1',
			'/search-console',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => static function (): bool {
						return Capabilities::canManage();
					},
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import' ),
					'permission_callback' => static function (): bool {
						return Capabilities::canManage();
					},
				),
			)
		);
	}

	public function show( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return Responses::json( ( new SearchRepository() )->summary() );
	}

	public function import( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$csv = (string) $request->get_param( 'csv' );
		if ( strlen( $csv ) > 2000000 ) {
			return Responses::error( 'seostudio_invalid', __( 'The file is larger than 2 MB.', 'seo-studio' ), 400, 'csv' );
		}
		$parsed = ( new CsvImport() )->parse( $csv );
		if ( '' !== $parsed['error'] ) {
			return Responses::error( 'seostudio_invalid', $parsed['error'], 400, 'csv' );
		}
		( new SearchRepository() )->replace( $parsed['rows'], $parsed['skipped'] );
		return Responses::json( ( new SearchRepository() )->summary() );
	}
}
