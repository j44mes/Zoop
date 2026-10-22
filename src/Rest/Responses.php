<?php
/**
 * Shared REST response shape.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use WP_Error;
use WP_REST_Request;
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

	public static function error( string $code, string $message, int $status, string $field = '', string $reason = '', ?int $fetchStatus = null ): WP_Error {
		$data = array( 'status' => $status );
		if ( '' !== $field ) {
			$data['field'] = $field;
		}
		if ( '' !== $reason ) {
			$data['reason'] = $reason;
		}
		if ( null !== $fetchStatus ) {
			$data['http_status'] = $fetchStatus;
		}

		return new WP_Error( $code, $message, $data );
	}

	/**
	 * Report a failed permission check on Zoop routes as seostudio_forbidden.
	 * WordPress names it rest_forbidden; the status (401 or 403) is kept.
	 *
	 * @param array<string, mixed> $handler
	 */
	public static function documentForbidden( mixed $response, array $handler, WP_REST_Request $request ): mixed {
		unset( $handler );
		if ( ! $response instanceof WP_Error || 'rest_forbidden' !== $response->get_error_code() ) {
			return $response;
		}
		if ( ! str_starts_with( $request->get_route(), '/seostudio/v1/' ) ) {
			return $response;
		}

		$data   = $response->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : rest_authorization_required_code();
		return self::error( 'seostudio_forbidden', __( 'You are not allowed to do that.', 'seo-studio' ), $status );
	}
}
