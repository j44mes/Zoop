<?php
/**
 * Search-appearance proposal routes.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Appearance\Decision;
use SEOStudio\Appearance\Fields;
use SEOStudio\Content\FetchBlockedException;
use SEOStudio\Content\LoopbackFetcher;
use SEOStudio\Content\Renderer;
use SEOStudio\Core\Capabilities;
use SEOStudio\Integrations\Detector;
use SEOStudio\Output\HeadOutput;
use SEOStudio\Storage\SeoMeta;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Saves a title and description proposal without publishing it.
 * Apply and restore run only when Zoop prints the tags.
 */
final class AppearanceController {
	public function __construct(
		private readonly Decision $decision = new Decision(),
		private readonly Renderer $renderer = new Renderer(),
		private readonly LoopbackFetcher $fetcher = new LoopbackFetcher(),
		private readonly HeadOutput $output = new HeadOutput(),
		private readonly Detector $detector = new Detector()
	) {}

	public function register(): void {
		register_rest_route(
			'seostudio/v1',
			'/posts/(?P<post_id>\d+)/appearance',
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
				),
			)
		);
		register_rest_route(
			'seostudio/v1',
			'/posts/(?P<post_id>\d+)/appearance/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply' ),
				'permission_callback' => array( $this, 'canEdit' ),
			)
		);
		register_rest_route(
			'seostudio/v1',
			'/posts/(?P<post_id>\d+)/appearance/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore' ),
				'permission_callback' => array( $this, 'canEdit' ),
			)
		);
	}

	public function canEdit( WP_REST_Request $request ): bool {
		return Capabilities::canEditPost( (int) $request['post_id'] );
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = $this->post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}

		return Responses::json( $this->payload( $post->ID, null ) );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = $this->post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}
		$clean = $this->clean( $request );
		if ( $clean instanceof WP_Error ) {
			return $clean;
		}

		$this->storeProposal( $post->ID, $clean['title'], $clean['description'] );
		return Responses::json( $this->payload( $post->ID, null ) );
	}

	public function apply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = $this->post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}
		$clean = $this->clean( $request );
		if ( $clean instanceof WP_Error ) {
			return $clean;
		}

		$publishing = $this->output->publishing();
		$result     = $this->decision->apply(
			$publishing,
			true === $request->get_param( 'confirm' ),
			$this->meta( $post->ID, '_seostudio_title' ),
			$this->meta( $post->ID, '_seostudio_description' ),
			$clean['title'],
			$clean['description']
		);
		if ( 'unsupported' === $result['status'] ) {
			return Responses::error( 'seostudio_not_publisher', __( 'Zoop is not printing the tags, so this proposal was not published.', 'seo-studio' ), 409 );
		}
		if ( 'unconfirmed' === $result['status'] ) {
			return Responses::error( 'seostudio_unconfirmed', __( 'Apply the proposal only after confirming the previous and proposed values.', 'seo-studio' ), 400 );
		}

		$this->storePrevious( $post->ID, $result['previous_title'], $result['previous_description'] );
		SeoMeta::store( $post->ID, '_seostudio_title', $result['title'] );
		SeoMeta::store( $post->ID, '_seostudio_description', $result['description'] );
		$this->storeProposal( $post->ID, $result['title'], $result['description'] );

		return Responses::json( $this->payload( $post->ID, $this->checkPublished( $post->ID ) ) );
	}

	public function restore( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = $this->post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}
		if ( ! $this->output->publishing() ) {
			return Responses::error( 'seostudio_not_publisher', __( 'Zoop is not printing the tags, so the previous values were not published.', 'seo-studio' ), 409 );
		}
		if ( true !== $request->get_param( 'confirm' ) ) {
			return Responses::error( 'seostudio_unconfirmed', __( 'Restore the previous values only after confirming them.', 'seo-studio' ), 400 );
		}
		if ( '1' !== $this->meta( $post->ID, Fields::PREVIOUS_SAVED ) ) {
			return Responses::error( 'seostudio_nothing_to_restore', __( 'There is no previous title to restore.', 'seo-studio' ), 400 );
		}

		$restored            = $this->decision->restore(
			$this->meta( $post->ID, Fields::PREVIOUS_TITLE ),
			$this->meta( $post->ID, Fields::PREVIOUS_DESCRIPTION )
		);
		$current_title       = $this->meta( $post->ID, '_seostudio_title' );
		$current_description = $this->meta( $post->ID, '_seostudio_description' );
		SeoMeta::store( $post->ID, '_seostudio_title', $restored['title'] );
		SeoMeta::store( $post->ID, '_seostudio_description', $restored['description'] );
		$this->storePrevious( $post->ID, $current_title, $current_description );

		return Responses::json( $this->payload( $post->ID, $this->checkPublished( $post->ID ) ) );
	}

	/**
	 * The post named by the route, or a not-found error.
	 *
	 * @return \WP_Post|WP_Error
	 */
	private function post( WP_REST_Request $request ): \WP_Post|WP_Error {
		$post = get_post( (int) $request['post_id'] );
		if ( ! $post instanceof \WP_Post ) {
			return Responses::error( 'seostudio_not_found', __( 'That post does not exist.', 'seo-studio' ), 404 );
		}
		return $post;
	}

	/**
	 * Proposed title and description, or a validation error.
	 *
	 * @return array{title: string, description: string}|WP_Error
	 */
	private function clean( WP_REST_Request $request ): array|WP_Error {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		$title       = isset( $params['title'] ) && is_string( $params['title'] ) ? $params['title'] : '';
		$description = isset( $params['description'] ) && is_string( $params['description'] ) ? $params['description'] : '';
		$clean       = $this->decision->proposal( $title, $description );
		if ( ! $clean['ok'] ) {
			return Responses::error( 'seostudio_validation', $clean['message'], 400, $clean['field'] );
		}

		return array(
			'title'       => $clean['title'],
			'description' => $clean['description'],
		);
	}

	private function storeProposal( int $postId, string $title, string $description ): void {
		SeoMeta::store( $postId, Fields::TITLE, $title );
		SeoMeta::store( $postId, Fields::DESCRIPTION, $description );
		update_post_meta( $postId, Fields::SAVED, '1' );
	}

	private function storePrevious( int $postId, string $title, string $description ): void {
		SeoMeta::store( $postId, Fields::PREVIOUS_TITLE, $title );
		SeoMeta::store( $postId, Fields::PREVIOUS_DESCRIPTION, $description );
		update_post_meta( $postId, Fields::PREVIOUS_SAVED, '1' );
	}

	/**
	 * Compare the published HTML with the title Zoop now intends to print.
	 *
	 * @return array{status: string, html_matches: bool, note: string, observed_title: string, checked_at: string}
	 */
	private function checkPublished( int $postId ): array {
		$post                 = get_post( $postId );
		$expected             = $post instanceof \WP_Post ? $this->renderer->publishedFields( $post ) : array(
			'title'       => '',
			'description' => '',
			'url'         => '',
		);
		$observed_title       = '';
		$observed_description = '';
		$fetched              = false;
		try {
			if ( '' !== $expected['url'] ) {
				$model                = $this->fetcher->fetch(
					$expected['url'],
					array(
						'page_type'        => get_post_type( $postId ),
						'content_selector' => $post instanceof \WP_Post ? $this->renderer->selectorFor( $post ) : '',
					)
				);
				$observed_title       = $model->titleTag;
				$observed_description = $model->metaDescription;
				$fetched              = true;
			}
		} catch ( FetchBlockedException ) {
			$fetched = false;
		}

		$verdict = $this->decision->verification(
			$fetched,
			$fetched && $this->decision->htmlMatches( $expected['title'], $observed_title, $expected['description'], $observed_description )
		);

		return array(
			'status'         => $verdict['status'],
			'html_matches'   => $verdict['html_matches'],
			'note'           => $verdict['note'],
			'observed_title' => $observed_title,
			'checked_at'     => gmdate( 'c' ),
		);
	}

	/**
	 * Published values, the saved proposal, and whether Zoop can apply it.
	 *
	 * @param array{status: string, html_matches: bool, note: string, observed_title: string, checked_at: string}|null $verification
	 * @return array<string, mixed>
	 */
	private function payload( int $postId, ?array $verification ): array {
		$post           = get_post( $postId );
		$published      = $post instanceof \WP_Post ? $this->renderer->publishedFields( $post ) : array(
			'title'       => '',
			'description' => '',
			'url'         => '',
		);
		$publishing     = $this->output->publishing();
		$active         = $this->detector->first();
		$saved          = '1' === $this->meta( $postId, Fields::SAVED );
		$previous_saved = '1' === $this->meta( $postId, Fields::PREVIOUS_SAVED );

		return array(
			'publishing'     => $publishing,
			'can_apply'      => $this->decision->canApply( $publishing ),
			'can_restore'    => $publishing && $previous_saved,
			'controller'     => $publishing ? __( 'Zoop', 'seo-studio' ) : ( is_array( $active ) ? $active['name'] : '' ),
			'edit_url'       => 'post.php?post=' . $postId . '&action=edit',
			'url'            => $published['url'],
			'published'      => array(
				'title'       => $published['title'],
				'description' => $published['description'],
			),
			'proposal_saved' => $saved,
			'proposal'       => array(
				'title'       => $saved ? $this->meta( $postId, Fields::TITLE ) : '',
				'description' => $saved ? $this->meta( $postId, Fields::DESCRIPTION ) : '',
			),
			'previous'       => array(
				'title'       => $previous_saved ? $this->meta( $postId, Fields::PREVIOUS_TITLE ) : '',
				'description' => $previous_saved ? $this->meta( $postId, Fields::PREVIOUS_DESCRIPTION ) : '',
			),
			'verification'   => $verification,
		);
	}

	private function meta( int $postId, string $key ): string {
		$value = get_post_meta( $postId, $key, true );
		return is_string( $value ) ? $value : '';
	}
}
