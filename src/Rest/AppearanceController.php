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
use SEOStudio\Appearance\ProposalStore;
use SEOStudio\Content\FetchBlockedException;
use SEOStudio\Content\LoopbackFetcher;
use SEOStudio\Content\Renderer;
use SEOStudio\Core\Capabilities;
use SEOStudio\Core\HomepageMetadata;
use SEOStudio\Core\Settings;
use SEOStudio\Integrations\Detector;
use SEOStudio\Output\HeadOutput;
use SEOStudio\Output\TitleFilter;
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
		$params   = $request->get_json_params();
		$revision = ProposalStore::revisionFrom( is_array( $params ) ? ( $params['proposal_revision'] ?? null ) : null );
		if ( null === $revision ) {
			return Responses::error( 'seostudio_stale', __( 'The proposal revision was missing from this update.', 'seo-studio' ), 409 );
		}
		$proposal = ( new ProposalStore() )->commit( $post->ID, $clean['title'], $clean['description'], $revision );
		if ( ! $proposal['ok'] ) {
			return Responses::error( $proposal['code'], $proposal['message'], $proposal['status'] );
		}
		$this->captureSuggestionBaseline( $post->ID );

		return Responses::json( $this->payload( $post->ID, null ) );
	}

	public function apply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post = $this->post( $request );
		if ( $post instanceof WP_Error ) {
			return $post;
		}
		if ( '1' !== $this->meta( $post->ID, Fields::SAVED ) ) {
			return Responses::error( 'seostudio_unsaved', __( 'Save the proposal before applying it.', 'seo-studio' ), 400 );
		}
		$params   = $request->get_json_params();
		$revision = ProposalStore::revisionFrom( is_array( $params ) ? ( $params['proposal_revision'] ?? null ) : null );
		if ( null === $revision || ( new ProposalStore() )->revision( $post->ID ) !== $revision ) {
			return Responses::error( 'seostudio_stale', __( 'A newer proposal was saved. Review it before applying.', 'seo-studio' ), 409 );
		}
		$baseline = $this->baseline( $post->ID );
		if ( '1' === $this->meta( $post->ID, Fields::SUGGESTION_BASELINE_SAVED ) && ( ! $this->decision->sameStored( $this->meta( $post->ID, Fields::SUGGESTION_BASELINE_TITLE ), $baseline['title'] ) || ! $this->decision->sameStored( $this->meta( $post->ID, Fields::SUGGESTION_BASELINE_DESCRIPTION ), $baseline['description'] ) ) ) {
			return Responses::error( 'seostudio_stale', __( 'The published title changed after this suggestion was saved. Review it again before applying.', 'seo-studio' ), 409 );
		}
		$locked = 'homepage' === $baseline['target'] && ! Capabilities::canManage();
		$seen   = $this->seenBaseline( $request );
		if ( $seen instanceof WP_Error ) {
			return $seen;
		}
		$result = $this->decision->apply(
			$this->output->publishing() && ! $locked,
			true === $request->get_param( 'confirm' ),
			$baseline['title'],
			$baseline['description'],
			$this->meta( $post->ID, Fields::TITLE ),
			$this->meta( $post->ID, Fields::DESCRIPTION ),
			$seen['title'],
			$seen['description']
		);
		if ( 'unsupported' === $result['status'] ) {
			if ( $locked ) {
				return Responses::error( 'seostudio_homepage_settings', __( 'The homepage title is a site setting. Apply is available to an administrator.', 'seo-studio' ), 403 );
			}
			return Responses::error( 'seostudio_not_publisher', __( 'Zoop is not printing the tags, so this proposal was not published.', 'seo-studio' ), 409 );
		}
		if ( 'unconfirmed' === $result['status'] ) {
			return Responses::error( 'seostudio_unconfirmed', __( 'Apply the proposal only after confirming the previous and proposed values.', 'seo-studio' ), 400 );
		}
		if ( 'stale' === $result['status'] ) {
			return Responses::error( 'seostudio_stale', __( 'The stored title or description changed before this confirmation. Review the current values and try again.', 'seo-studio' ), 409 );
		}

		$this->storePrevious( $post->ID, $result['previous_title'], $result['previous_description'], $baseline['target'] );
		$this->writeControlled( $post->ID, $baseline['target'], $result['title'], $result['description'] );

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

		$baseline = $this->baseline( $post->ID );
		$target   = $this->meta( $post->ID, Fields::PREVIOUS_TARGET );
		if ( '' === $target ) {
			$target = 'post';
		}
		if ( 'homepage' === $target && ! HomepageMetadata::isStaticPage( $post->ID ) ) {
			return Responses::error( 'seostudio_homepage_settings', __( 'This page no longer controls the homepage title.', 'seo-studio' ), 409 );
		}
		if ( 'homepage' === $target && ! Capabilities::canManage() ) {
			return Responses::error( 'seostudio_homepage_settings', __( 'The homepage title is a site setting. Restore is available to an administrator.', 'seo-studio' ), 403 );
		}
		$seen = $this->seenBaseline( $request );
		if ( $seen instanceof WP_Error ) {
			return $seen;
		}
		if ( ! $this->decision->sameStored( $seen['title'], $baseline['title'] ) || ! $this->decision->sameStored( $seen['description'], $baseline['description'] ) ) {
			return Responses::error( 'seostudio_stale', __( 'The stored title or description changed before this confirmation. Review the current values and try again.', 'seo-studio' ), 409 );
		}

		$restored = $this->decision->restore(
			$this->meta( $post->ID, Fields::PREVIOUS_TITLE ),
			$this->meta( $post->ID, Fields::PREVIOUS_DESCRIPTION )
		);
		$this->writeControlled( $post->ID, $target, $restored['title'], $restored['description'] );
		$this->storePrevious( $post->ID, $baseline['title'], $baseline['description'], $target );

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

	private function captureSuggestionBaseline( int $postId ): void {
		$baseline = $this->baseline( $postId );
		update_post_meta( $postId, Fields::SUGGESTION_BASELINE_TITLE, $baseline['title'] );
		update_post_meta( $postId, Fields::SUGGESTION_BASELINE_DESCRIPTION, $baseline['description'] );
		update_post_meta( $postId, Fields::SUGGESTION_BASELINE_SAVED, '1' );
	}

	private function storePrevious( int $postId, string $title, string $description, string $target ): void {
		SeoMeta::store( $postId, Fields::PREVIOUS_TITLE, $title );
		SeoMeta::store( $postId, Fields::PREVIOUS_DESCRIPTION, $description );
		update_post_meta( $postId, Fields::PREVIOUS_SAVED, '1' );
		update_post_meta( $postId, Fields::PREVIOUS_TARGET, $target );
	}

	/**
	 * The raw values Apply will replace. A static homepage uses the site settings.
	 *
	 * @return array{title: string, description: string, target: string}
	 */
	private function baseline( int $postId ): array {
		if ( HomepageMetadata::isStaticPage( $postId ) ) {
			$settings = Settings::all();
			return array(
				'title'       => (string) ( $settings['home_title'] ?? '' ),
				'description' => (string) ( $settings['home_description'] ?? '' ),
				'target'      => 'homepage',
			);
		}

		return array(
			'title'       => $this->meta( $postId, '_seostudio_title' ),
			'description' => $this->meta( $postId, '_seostudio_description' ),
			'target'      => 'post',
		);
	}

	/**
	 * Baseline the person confirmed. A missing field is not an empty value.
	 *
	 * @return array{title: string, description: string}|WP_Error
	 */
	private function seenBaseline( WP_REST_Request $request ): array|WP_Error {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}
		if ( ! array_key_exists( 'baseline_title', $params ) || ! is_string( $params['baseline_title'] ) || ! array_key_exists( 'baseline_description', $params ) || ! is_string( $params['baseline_description'] ) ) {
			return Responses::error( 'seostudio_stale', __( 'Confirm the stored title and description that this change will replace.', 'seo-studio' ), 409 );
		}

		return array(
			'title'       => $params['baseline_title'],
			'description' => $params['baseline_description'],
		);
	}

	/**
	 * Write the title and description that actually control this page.
	 */
	private function writeControlled( int $postId, string $target, string $title, string $description ): void {
		if ( 'homepage' === $target ) {
			$settings                     = Settings::all();
			$settings['home_title']       = $title;
			$settings['home_description'] = $description;
			update_option( Settings::OPTION, Settings::sanitize( $settings ), false );
			return;
		}

		SeoMeta::store( $postId, '_seostudio_title', $title );
		SeoMeta::store( $postId, '_seostudio_description', $description );
	}

	/**
	 * Compare the published HTML with the title Zoop now intends to print.
	 *
	 * Compare fetched HTML with values Zoop's stored fields fully determine.
	 *
	 * @return array<string, mixed>
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

		$expectation = $this->controlledExpectation( $postId );
		$verdict     = $this->judgeObservation( $fetched, $expectation, $observed_title, $observed_description );

		return array(
			'status'                 => $verdict['status'],
			'html_matches'           => $verdict['html_matches'],
			'basis'                  => $verdict['basis'],
			'note'                   => $verdict['note'],
			'title_state'            => $verdict['title_state'],
			'description_state'      => $verdict['description_state'],
			'calculated_title'       => $expectation['title'],
			'calculated_description' => $expectation['description'],
			'observed_title'         => $fetched ? $observed_title : '',
			'observed_description'   => $fetched ? $observed_description : '',
			'checked_at'             => gmdate( 'c' ),
		);
	}

	/**
	 * Values Zoop's stored fields fully determine. Theme fallbacks stay null.
	 *
	 * @return array{title: string|null, description: string|null}
	 */
	private function controlledExpectation( int $postId ): array {
		if ( HomepageMetadata::isStaticPage( $postId ) ) {
			$home = HomepageMetadata::resolve();
			return array(
				'title'       => $home['title_tag'],
				'description' => $home['meta_description'],
			);
		}
		$title       = $this->meta( $postId, '_seostudio_title' );
		$description = $this->meta( $postId, '_seostudio_description' );
		return array(
			'title'       => '' === trim( $title ) ? null : ( new TitleFilter() )->expand( $postId, $title ),
			'description' => '' === trim( $description ) ? null : trim( $description ),
		);
	}

	/**
	 * Compare observed tags with values Zoop fully determines.
	 *
	 * @param array{title: string|null, description: string|null} $expectation
	 * @return array{status: string, html_matches: bool, basis: string, note: string, title_state: string, description_state: string}
	 */
	private function judgeObservation( bool $fetched, array $expectation, string $observed_title, string $observed_description ): array {
		if ( ! $fetched ) {
			return array(
				'status'            => 'not_verified',
				'html_matches'      => false,
				'basis'             => 'not_observed',
				'note'              => __( 'The live HTML was not read. A calculated title is not a verified publication.', 'seo-studio' ),
				'title_state'       => 'not_verified',
				'description_state' => 'not_verified',
			);
		}
		$title_state       = $this->fieldState( $expectation['title'], $observed_title );
		$description_state = $this->fieldState( $expectation['description'], $observed_description );
		$states            = array();
		if ( null !== $expectation['title'] || '' !== trim( $observed_title ) ) {
			$states[] = $title_state;
		}
		if ( null !== $expectation['description'] || '' !== trim( $observed_description ) ) {
			$states[] = $description_state;
		}
		$matches = array() !== $states && ! in_array( 'mismatch', $states, true ) && ! in_array( 'not_verified', $states, true );
		if ( in_array( 'mismatch', $states, true ) ) {
			$note   = __( 'The published HTML was observed and does not match the title or description Zoop applied.', 'seo-studio' );
			$status = 'mismatch';
		} elseif ( $matches ) {
			$note   = __( 'The published HTML was observed and matches the title and description Zoop applied.', 'seo-studio' );
			$status = 'verified';
		} else {
			$note   = __( 'The published HTML was observed. Zoop is not treating a theme title or generated excerpt as a verified tag.', 'seo-studio' );
			$status = 'not_verified';
		}
		return array(
			'status'            => $status,
			'html_matches'      => $matches,
			'basis'             => 'live_html',
			'note'              => $note,
			'title_state'       => $title_state,
			'description_state' => $description_state,
		);
	}

	private function fieldState( ?string $calculated, string $observed ): string {
		if ( null === $calculated ) {
			return 'not_verified';
		}
		$fold = static function ( string $value ): string {
			$folded = preg_replace( '/\s+/u', ' ', trim( $value ) );
			return is_string( $folded ) ? $folded : trim( $value );
		};
		return $fold( $calculated ) === $fold( $observed ) ? 'verified' : 'mismatch';
	}

	/**
	 * Published values, the saved proposal, and whether Zoop can apply it.
	 *
	 * @param array<string, mixed>|null $verification
	 * @return array<string, mixed>
	 */
	private function payload( int $postId, ?array $verification ): array {
		$post           = get_post( $postId );
		$resolved       = $post instanceof \WP_Post ? $this->renderer->publishedFields( $post ) : array(
			'title'       => '',
			'description' => '',
			'url'         => '',
		);
		$baseline       = $this->baseline( $postId );
		$publishing     = $this->output->publishing();
		$locked         = 'homepage' === $baseline['target'] && ! Capabilities::canManage();
		$active         = $this->detector->first();
		$saved          = '1' === $this->meta( $postId, Fields::SAVED );
		$previous_saved = '1' === $this->meta( $postId, Fields::PREVIOUS_SAVED );

		return array(
			'publishing'        => $publishing,
			'can_apply'         => $this->decision->canApply( $publishing ) && ! $locked,
			'can_restore'       => $publishing && $previous_saved && ! $locked,
			'controller'        => $publishing ? __( 'Zoop', 'seo-studio' ) : ( is_array( $active ) ? $active['name'] : '' ),
			'edit_url'          => 'post.php?post=' . $postId . '&action=edit',
			'url'               => $resolved['url'],
			'resolved'          => array(
				'title'       => $resolved['title'],
				'description' => $resolved['description'],
				'basis'       => 'saved_settings',
			),
			'published'         => array(
				'title'       => $resolved['title'],
				'description' => $resolved['description'],
			),
			'baseline'          => array(
				'title'       => $baseline['title'],
				'description' => $baseline['description'],
				'target'      => $baseline['target'],
			),
			'proposal_saved'    => $saved,
			'proposal_revision' => ( new ProposalStore() )->revision( $postId ),
			'proposal'          => array(
				'title'       => $saved ? $this->meta( $postId, Fields::TITLE ) : '',
				'description' => $saved ? $this->meta( $postId, Fields::DESCRIPTION ) : '',
			),
			'previous'          => array(
				'title'       => $previous_saved ? $this->meta( $postId, Fields::PREVIOUS_TITLE ) : '',
				'description' => $previous_saved ? $this->meta( $postId, Fields::PREVIOUS_DESCRIPTION ) : '',
			),
			'verification'      => $verification,
		);
	}

	private function meta( int $postId, string $key ): string {
		$value = get_post_meta( $postId, $key, true );
		return is_string( $value ) ? $value : '';
	}
}
