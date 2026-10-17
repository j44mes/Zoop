<?php
/**
 * Shared REST response shape.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use WP_Error;
use WP_REST_Response;

/**
 * Adds the plugin version header and the documented error codes.
 */
final class Responses {
	/**
	 * JSON response with the plugin version header.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function json( array $data, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $data, $status );
		$response->header( 'X-SEOStudio-Version', defined( 'SEOSTUDIO_VERSION' ) ? SEOSTUDIO_VERSION : '0.1.0' );
		return $response;
	}

	public static function error( string $code, string $message, int $status, string $field = '', string $reason = '' ): WP_Error {
		$data = array( 'status' => $status );
		if ( '' !== $field ) {
			$data['field'] = $field;
		}
		if ( '' !== $reason ) {
			$data['reason'] = $reason;
		}

		return new WP_Error( $code, $message, $data );
	}
}
