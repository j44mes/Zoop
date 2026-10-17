<?php
/**
 * Per-post SEO meta routes.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Rest;

use SEOStudio\Content\TemplateValues;
use SEOStudio\Core\Capabilities;
use SEOStudio\Core\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Reads and writes the SEO fields stored on a post.
 */
final class PostSeoController {
	public function __construct( private readonly SeoValidator $validator = new SeoValidator() ) {}

	public function register(): void {
		self::registerValidationFilter();
		register_rest_route(
			'seostudio/v1',
			'/posts/(?P<post_id>\d+)/seo',
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
					'args'                => $this->args(),
				),
			)
		);
	}

	/**
	 * Attach the validator on every rest_api_init.
	 *
	 * The test suite restores hooks to the state before the first test, which drops
	 * callbacks added during that test. Registering again keeps the field errors intact.
	 */
	public static function registerValidationFilter(): void {
		static $controller = null;
		if ( ! $controller instanceof self ) {
			$controller = new self();
		}

		remove_filter( 'rest_pre_dispatch', array( $controller, 'rejectInvalidSeo' ), 10 );
		add_filter( 'rest_pre_dispatch', array( $controller, 'rejectInvalidSeo' ), 10, 3 );
	}

	/**
	 * Route schema. The callback validates the whole payload again so partial JSON is covered.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function args(): array {
		$string = static function ( int $max ): array {
			return array(
				'type'              => 'string',
				'maxLength'         => $max,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( mixed $value ) use ( $max ): bool {
					return is_string( $value ) && mb_strlen( trim( $value ) ) <= $max;
				},
			);
		};

		return array(
			'focus_keyphrase'    => $string( 255 ),
			'title'              => $string( 300 ),
			'og_title'           => $string( 300 ),
			'description'        => $string( 500 ),
			'og_description'     => $string( 500 ),
			'content_selector'   => $string( 255 ),
			'canonical'          => $string( 2048 ),
			'related_keyphrases' => array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			'synonyms'           => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'robots'             => array(
				'type'       => 'object',
				'properties' => array(
					'index'             => array( 'type' => array( 'boolean', 'null' ) ),
					'follow'            => array( 'type' => array( 'boolean', 'null' ) ),
					'max_snippet'       => array( 'type' => array( 'integer', 'null' ) ),
					'max_image_preview' => array(
						'type' => array( 'string', 'null' ),
						'enum' => array( 'none', 'standard', 'large', null ),
					),
				),
			),
			'og_image'           => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'cornerstone'        => array(
				'type' => 'boolean',
			),
		);
	}

	/**
	 * Schema validation would otherwise hide the field name behind rest_invalid_param.
	 *
	 * @param mixed           $result  Response to short-circuit, or null.
	 * @param WP_REST_Server  $server  Server instance.
	 * @param WP_REST_Request $request Request used to generate the response.
	 * @return mixed
	 */
	public function rejectInvalidSeo( mixed $result, \WP_REST_Server $server, WP_REST_Request $request ): mixed {
		unset( $server );
		if ( null !== $result || 1 !== preg_match( '#^/seostudio/v1/posts/[0-9]+/seo/?$#D', $request->get_route() ) ) {
			return $result;
		}
		if ( ! in_array( $request->get_method(), array( 'PUT', 'POST', 'PATCH' ), true ) ) {
			return $result;
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return $result;
		}

		$error = $this->validator->validate( $params, (string) home_url( '/' ) );
		if ( null === $error ) {
			return $result;
		}

		return Responses::error( 'seostudio_validation', $error['message'], 400, $error['field'] );
	}

	public function canEdit( WP_REST_Request $request ): bool {
		return Capabilities::canEditPost( (int) $request['post_id'] );
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request['post_id'];
		if ( ! get_post( $post_id ) ) {
			return Responses::error( 'seostudio_not_found', __( 'That post does not exist.', 'seo-studio' ), 404 );
		}

		return Responses::json( $this->payload( $post_id ) );
	}

	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request['post_id'];
		if ( ! get_post( $post_id ) ) {
			return Responses::error( 'seostudio_not_found', __( 'That post does not exist.', 'seo-studio' ), 404 );
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$error = $this->validator->validate( $params, (string) home_url( '/' ) );
		if ( null !== $error ) {
			return Responses::error( 'seostudio_validation', $error['message'], 400, $error['field'] );
		}

		foreach ( SeoValidator::FIELDS as $name => $meta_key ) {
			if ( ! array_key_exists( $name, $params ) ) {
				continue;
			}
			$this->store( $post_id, $meta_key, $params[ $name ] );
		}

		return $this->show( $request );
	}

	private function store( int $postId, string $metaKey, mixed $value ): void {
		$empty = ( is_string( $value ) && '' === trim( $value ) ) || ( is_int( $value ) && 0 === $value && '_seostudio_og_image' === $metaKey );
		if ( $empty ) {
			delete_post_meta( $postId, $metaKey );
			return;
		}

		if ( is_string( $value ) && '_seostudio_description' === $metaKey ) {
			$value = sanitize_textarea_field( $value );
		} elseif ( is_string( $value ) && '_seostudio_canonical' === $metaKey ) {
			$value = esc_url_raw( $value );
		} elseif ( is_string( $value ) ) {
			$value = sanitize_text_field( $value );
		}

		update_post_meta( $postId, $metaKey, $value );
	}

	/**
	 * Stored SEO fields plus the editor preview of resolved title and description.
	 *
	 * @return array<string, mixed>
	 */
	private function payload( int $postId ): array {
		$data = array();
		foreach ( SeoValidator::FIELDS as $name => $meta_key ) {
			$data[ $name ] = get_post_meta( $postId, $meta_key, true );
		}

		$settings                     = Settings::all();
		$values                       = new TemplateValues(
			wp_strip_all_tags( get_the_title( $postId ) ),
			wp_strip_all_tags( get_bloginfo( 'name' ) ),
			(string) ( $settings['site_title_separator'] ?? '–' ),
			wp_strip_all_tags( has_excerpt( $postId ) ? get_the_excerpt( $postId ) : '' ),
			'',
			wp_strip_all_tags( get_bloginfo( 'description' ) )
		);
		$title                        = is_string( $data['title'] ) ? $data['title'] : '';
		$desc                         = is_string( $data['description'] ) ? $data['description'] : '';
		$data['resolved_title']       = '' === trim( $title ) ? $values->title : $values->expand( $title );
		$data['resolved_description'] = '' === trim( $desc ) ? $values->excerpt : $values->expand( $desc );
		return $data;
	}
}
