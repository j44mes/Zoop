<?php
/**
 * Export and import of Zoop's own data.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Core\Capabilities;
use SEOStudio\Core\Settings;
use SEOStudio\Integrations\Detector;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Export writes settings and meta. Import restores those and never writes scores.
 */
final class ToolsController {
	public function register(): void {
		register_rest_route(
			'seostudio/v1',
			'/export',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'export' ),
					'permission_callback' => array( $this, 'canManage' ),
				),
			)
		);

		register_rest_route(
			'seostudio/v1',
			'/import',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import' ),
					'permission_callback' => array( $this, 'canManage' ),
				),
			)
		);
	}

	public function canManage(): bool {
		return Capabilities::canManage();
	}

	public function export(): WP_REST_Response {
		global $wpdb;

		$keys         = array_values( SeoValidator::FIELDS );
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		$sql          = "SELECT DISTINCT post_id, meta_key FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) ORDER BY post_id ASC";
		$prepared     = $wpdb->prepare( $sql, $keys ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Placeholders match the key list.
		$rows         = is_string( $prepared ) ? $wpdb->get_results( $prepared, ARRAY_A ) : array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.

		// Values are exported decoded, so an import never has to unserialize anything.
		$fields = array_flip( SeoValidator::FIELDS );
		$meta   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$post_id = (int) $row['post_id'];
			$key     = (string) $row['meta_key'];
			$meta[]  = array(
				'post_id'    => $post_id,
				'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Export field name, not a query.
				'meta_value' => $this->normalise( $fields[ $key ], get_post_meta( $post_id, $key, true ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Export field name, not a query.
			);
		}

		return Responses::json(
			array(
				'settings' => Settings::all(),
				'meta'     => $meta,
			)
		);
	}

	public function import( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return Responses::error( 'seostudio_validation', __( 'The import body must be a JSON object.', 'seo-studio' ), 400 );
		}

		if ( isset( $params['settings'] ) && is_array( $params['settings'] ) ) {
			$clean = Settings::sanitize( $params['settings'] );
			if ( 'owner' === $clean['mode'] && null !== ( new Detector() )->first() ) {
				$clean['mode'] = 'analysis';
			}
			update_option( Settings::OPTION, $clean, true );
		}

		$fields    = array_flip( SeoValidator::FIELDS );
		$validator = new SeoValidator();
		$home      = (string) home_url( '/' );
		$meta      = isset( $params['meta'] ) && is_array( $params['meta'] ) ? $params['meta'] : array();
		$written   = 0;
		$skipped   = array();
		foreach ( $meta as $index => $row ) {
			$reason = $this->rejection( $row, $fields );
			if ( null === $reason ) {
				$key   = (string) $row['meta_key'];
				$field = $fields[ $key ];
				$value = $this->normalise( $field, $row['meta_value'] ?? null );
				$error = $validator->validate( array( $field => $value ), $home );
				if ( null === $error ) {
					PostSeoController::store( (int) $row['post_id'], $key, $value );
					++$written;
					continue;
				}
				$reason = $error['message'];
			}
			if ( count( $skipped ) < 100 ) {
				$skipped[] = array(
					'row'    => (int) $index,
					'reason' => $reason,
				);
			}
		}

		return Responses::json(
			array(
				'meta_written' => $written,
				'meta_skipped' => count( $meta ) - $written,
				'skipped'      => $skipped,
				'settings'     => Settings::all(),
			)
		);
	}

	/**
	 * Why an uploaded row cannot be written, or null when it may be validated.
	 *
	 * @param array<string, string> $fields Meta key to field name.
	 */
	private function rejection( mixed $row, array $fields ): ?string {
		if ( ! is_array( $row ) ) {
			return 'Each row must be an object.';
		}
		if ( ! isset( $fields[ (string) ( $row['meta_key'] ?? '' ) ] ) ) {
			return 'The meta key is not a Zoop field.';
		}
		$post_id = (int) ( $row['post_id'] ?? 0 );
		if ( ! get_post( $post_id ) instanceof \WP_Post ) {
			return 'The post does not exist on this site.';
		}
		if ( ! Capabilities::canEditPost( $post_id ) ) {
			return 'You cannot edit that post.';
		}
		$value = $row['meta_value'] ?? null;
		// Uploaded files are untrusted: serialized PHP is never unserialized.
		if ( is_string( $value ) && is_serialized( $value ) ) {
			return 'Serialized values are not accepted.';
		}

		return null;
	}

	/**
	 * Convert a stored or uploaded value to the shape the field contract expects.
	 */
	private function normalise( string $field, mixed $value ): mixed {
		return match ( $field ) {
			'og_image' => is_numeric( $value ) ? (int) $value : $value,
			'cornerstone', 'is_news' => is_bool( $value ) ? $value : in_array( $value, array( 1, '1', 'true' ), true ),
			default => $value,
		};
	}
}
