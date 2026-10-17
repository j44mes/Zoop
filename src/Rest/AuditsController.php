<?php
/**
 * Site audit route.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Core\Capabilities;
use SEOStudio\Jobs\AuditBatch;
use SEOStudio\Storage\AuditRunsRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Starts, reads, and cancels a site audit. Requires manage_options.
 */
final class AuditsController {
	public function __construct( private readonly AuditRunsRepository $runs = new AuditRunsRepository() ) {}

	public function register(): void {
		register_rest_route(
			'seostudio/v1',
			'/audits',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => static function (): bool {
						return Capabilities::canManage();
					},
					'args'                => array(
						'type' => array(
							'type'              => 'string',
							'default'           => 'audit',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		register_rest_route(
			'seostudio/v1',
			'/audits/(?P<run_id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => static function (): bool {
						return Capabilities::canManage();
					},
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => static function (): bool {
						return Capabilities::canManage();
					},
				),
			)
		);
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );
		$limited = $this->rateLimit();
		if ( $limited instanceof WP_Error ) {
			return $limited;
		}

		$active = $this->runs->active( 'audit' );
		if ( null !== $active ) {
			return Responses::error(
				'seostudio_conflict',
				__( 'An audit is already running.', 'seo-studio' ),
				409
			);
		}

		$run_id = ( new AuditBatch() )->start();
		$row    = $this->runs->find( $run_id );
		$body   = null === $row ? array( 'run_id' => $run_id ) : $this->runs->present( $row );
		return Responses::json( $body, 202 );
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->runs->find( (int) $request['run_id'] );
		if ( null === $row ) {
			return Responses::error( 'seostudio_not_found', __( 'That audit does not exist.', 'seo-studio' ), 404 );
		}

		return Responses::json( $this->runs->present( $row ) );
	}

	public function destroy( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->runs->find( (int) $request['run_id'] );
		if ( null === $row ) {
			return Responses::error( 'seostudio_not_found', __( 'That audit does not exist.', 'seo-studio' ), 404 );
		}

		if ( in_array( (string) $row['status'], array( 'queued', 'running' ), true ) ) {
			$this->runs->finish( (int) $row['id'], 'cancelled' );
		}

		return Responses::json( array( 'cancelled' => true ), 200 );
	}

	private function rateLimit(): ?WP_Error {
		if ( defined( 'SEOSTUDIO_JOBS_SYNC' ) && SEOSTUDIO_JOBS_SYNC ) {
			return null;
		}

		$user = get_current_user_id();
		$key  = 'seostudio_audit_user_' . $user;
		if ( get_transient( $key ) ) {
			return Responses::error(
				'seostudio_conflict',
				__( 'An audit was started recently. Wait a few minutes and try again.', 'seo-studio' ),
				429
			);
		}

		set_transient( $key, 1, 10 * MINUTE_IN_SECONDS );
		return null;
	}
}
