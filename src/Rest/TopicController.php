<?php
/**
 * Topic suggestion route.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Core\Capabilities;
use SEOStudio\Topics\Suggester;
use SEOStudio\Topics\TopicPage;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Reads and stores topic corrections. Suggestions stay available when a field is cleared.
 */
final class TopicController {
	public function __construct( private readonly TopicPage $topics = new TopicPage() ) {}

	public function register(): void {
		register_rest_route(
			'seostudio/v1',
			'/posts/(?P<post_id>\d+)/topic',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'canEdit' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'canEdit' ),
					'args'                => array(
						'primary_topic'       => $this->textArg(),
						'parent_topic'        => $this->textArg(),
						'page_role'           => $this->choiceArg( Suggester::ROLES ),
						'search_intent'       => $this->choiceArg( Suggester::INTENTS ),
						'supporting_entities' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
			)
		);
	}

	public function canEdit( WP_REST_Request $request ): bool {
		return Capabilities::canEditPost( (int) $request['post_id'] );
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request['post_id'];
		if ( ! get_post( $post_id ) ) {
			return Responses::error( 'seostudio_not_found', __( 'That post does not exist.', 'seo-studio' ), 404 );
		}

		return Responses::json( $this->topics->present( $post_id ) );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request['post_id'];
		if ( ! get_post( $post_id ) ) {
			return Responses::error( 'seostudio_not_found', __( 'That post does not exist.', 'seo-studio' ), 404 );
		}
		$entities = $request->get_param( 'supporting_entities' );
		if ( null !== $entities && ( ! is_array( $entities ) || count( $entities ) > 8 ) ) {
			return Responses::error( 'seostudio_invalid', __( 'Save at most eight supporting phrases.', 'seo-studio' ), 400, 'supporting_entities' );
		}

		return Responses::json( $this->topics->update( $post_id, $request->get_params() ) );
	}

	/**
	 * Schema for a free-text topic field.
	 *
	 * @return array<string, mixed>
	 */
	private function textArg(): array {
		return array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
		);
	}

	/**
	 * Schema for a role or intent, including a blank that clears the correction.
	 *
	 * @param string[] $allowed
	 * @return array<string, mixed>
	 */
	private function choiceArg( array $allowed ): array {
		return array(
			'type'              => 'string',
			'enum'              => array_merge( array( '' ), $allowed ),
			'sanitize_callback' => 'sanitize_key',
		);
	}
}
