<?php
/**
 * Editor saves for proposal and directly published fields.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Appearance;

use SEOStudio\Core\HomepageMetadata;
use SEOStudio\Rest\SeoValidator;
use SEOStudio\Storage\SeoMeta;
use WP_Post;
use WP_REST_Request;

/**
 * Gutenberg participates in the core post save. Classic posts named fields.
 */
final class EditorMeta {
	private const NOTICE = 'seostudio_notice';

	public static function register(): void {
		add_action( 'init', array( self::class, 'registerMeta' ), 20 );
		if ( did_action( 'init' ) ) {
			self::registerMeta();
		}
		add_filter( 'rest_pre_insert_post', array( self::class, 'beforeRestSave' ), 10, 2 );
		add_filter( 'rest_pre_insert_page', array( self::class, 'beforeRestSave' ), 10, 2 );
		add_filter( 'update_post_metadata', array( ProposalStore::class, 'blockExternalRevision' ), 10, 4 );
		add_action( 'save_post', array( self::class, 'classicSave' ), 10, 2 );
		add_filter( 'redirect_post_location', array( self::class, 'redirectNotice' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_filter( 'rest_prepare_post', array( self::class, 'hideMetaFromReaders' ), 10, 2 );
		add_filter( 'rest_prepare_page', array( self::class, 'hideMetaFromReaders' ), 10, 2 );
	}

	/**
	 * Editor fields are writable in the block editor. Readers do not receive them.
	 *
	 * @param mixed $response
	 * @param mixed $post
	 * @return mixed
	 */
	public static function hideMetaFromReaders( $response, $post ) {
		if ( ! $response instanceof \WP_REST_Response || ! $post instanceof WP_Post || current_user_can( 'edit_post', $post->ID ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( ! is_array( $data ) || ! isset( $data['meta'] ) || ! is_array( $data['meta'] ) ) {
			return $response;
		}
		foreach ( array_keys( $data['meta'] ) as $key ) {
			if ( is_string( $key ) && str_starts_with( $key, '_seostudio_' ) ) {
				unset( $data['meta'][ $key ] );
			}
		}
		$response->set_data( $data );
		return $response;
	}

	public static function registerMeta(): void {
		foreach ( array( 'post', 'page' ) as $type ) {
			if ( ! post_type_supports( $type, 'custom-fields' ) ) {
				add_post_type_support( $type, 'custom-fields' );
			}
		}
		$auth   = static function ( $allowed, $meta_key, $object_id ): bool {
			unset( $allowed, $meta_key );
			return current_user_can( 'edit_post', (int) $object_id );
		};
		$string = static function ( string $key, int $max, string $sanitize ) use ( $auth ): void {
			register_post_meta(
				'',
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => array(
						'schema' => array(
							'type'      => 'string',
							'maxLength' => $max,
						),
					),
					'auth_callback'     => $auth,
					'sanitize_callback' => $sanitize,
				)
			);
		};
		$string( '_seostudio_title', 300, 'sanitize_text_field' );
		$string( '_seostudio_description', 500, 'sanitize_textarea_field' );
		$string( '_seostudio_canonical', 2048, 'sanitize_text_field' );
		$string( '_seostudio_og_title', 300, 'sanitize_text_field' );
		$string( '_seostudio_og_description', 500, 'sanitize_text_field' );
		$string( '_seostudio_focus_keyphrase', 255, 'sanitize_text_field' );
		$string( '_seostudio_video_url', 2048, 'esc_url_raw' );
		register_post_meta(
			'',
			'_seostudio_robots',
			array(
				'type'          => 'object',
				'single'        => true,
				'show_in_rest'  => array(
					'schema' => array(
						'type'       => 'object',
						'properties' => array(
							'index'  => array( 'type' => 'boolean' ),
							'follow' => array( 'type' => 'boolean' ),
						),
					),
				),
				'auth_callback' => $auth,
			)
		);
		register_post_meta(
			'',
			'_seostudio_related_keyphrases',
			array(
				'type'          => 'array',
				'single'        => true,
				'show_in_rest'  => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'keyphrase' => array( 'type' => 'string' ),
								'synonyms'  => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'auth_callback' => $auth,
			)
		);
		register_post_meta(
			'',
			'_seostudio_synonyms',
			array(
				'type'          => 'array',
				'single'        => true,
				'show_in_rest'  => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'auth_callback' => $auth,
			)
		);
		register_post_meta(
			'',
			'_seostudio_is_news',
			array(
				'type'          => 'boolean',
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => $auth,
			)
		);
	}

	/**
	 * The static homepage does not publish its post title or description.
	 *
	 * @param mixed $prepared
	 * @return mixed
	 */
	public static function beforeRestSave( $prepared, WP_REST_Request $request ) {
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$post_id = (int) $request->get_param( 'id' );
		if ( $post_id <= 0 || ! HomepageMetadata::isStaticPage( $post_id ) ) {
			return $prepared;
		}
		$meta = $request->get_param( 'meta' );
		if ( ! is_array( $meta ) ) {
			return $prepared;
		}
		unset( $meta['_seostudio_title'], $meta['_seostudio_description'] );
		$request->set_param( 'meta', $meta );
		return $prepared;
	}

	/**
	 * Read the classic editor fields. An invalid SEO value does not stop the post save.
	 *
	 * @param mixed $post
	 */
	public static function classicSave( int $postId, $post ): void {
		if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || wp_is_post_autosave( $postId ) || wp_is_post_revision( $postId ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! $post instanceof WP_Post || ! self::classicRequest() ) {
			return;
		}
		if ( ! isset( $_POST['seostudio_metabox_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['seostudio_metabox_nonce'] ) ), 'seostudio_metabox' ) ) {
			self::rememberNotice( 'nonce' );
			return;
		}
		if ( ! current_user_can( 'edit_post', $postId ) ) {
			return;
		}
		self::saveDirect( $postId );
		self::savePublished( $postId );
	}

	public static function redirectNotice( string $location ): string {
		$notice = self::pullNotice();
		if ( '' === $notice ) {
			return $location;
		}
		return add_query_arg( self::NOTICE, $notice, $location );
	}

	public static function notice(): void {
		$flag     = isset( $_GET[ self::NOTICE ] ) ? sanitize_key( (string) $_GET[ self::NOTICE ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A display flag set by our own redirect.
		$messages = array(
			'nonce'    => __( 'The SEO fields were not saved because the security check failed. The rest of the update was kept.', 'seo-studio' ),
			'conflict' => __( 'The SEO title and description were not saved because a newer edit was saved first. Review the current proposal. The rest of the update was kept.', 'seo-studio' ),
			'invalid'  => __( 'The SEO title and description were not saved because they are not valid. The rest of the update was kept.', 'seo-studio' ),
			'failed'   => __( 'The SEO title and description were not saved because the database write failed. The rest of the update was kept.', 'seo-studio' ),
			'homepage' => __( 'The homepage title and description are site settings. This update did not change them.', 'seo-studio' ),
		);
		if ( ! isset( $messages[ $flag ] ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html( $messages[ $flag ] ) . '</p></div>';
	}

	private static function classicRequest(): bool {
		foreach ( self::classicKeys() as $key ) {
			if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence only. The nonce is checked before any write.
				return true;
			}
		}
		return false;
	}

	/**
	 * Names of the classic editor inputs.
	 *
	 * @return string[]
	 */
	private static function classicKeys(): array {
		return array(
			'seostudio_metabox_nonce',
			'_seostudio_title',
			'_seostudio_description',
			'_seostudio_canonical',
			'_seostudio_og_title',
			'_seostudio_og_description',
			'_seostudio_focus_keyphrase',
			'seostudio_robots_index',
			'seostudio_robots_follow',
			'seostudio_related_keyphrases',
			'seostudio_synonyms',
			'_seostudio_video_url',
			'seostudio_is_news',
		);
	}

	private static function savePublished( int $postId ): void {
		$title_posted       = isset( $_POST['_seostudio_title'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified the nonce.
		$description_posted = isset( $_POST['_seostudio_description'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified the nonce.
		if ( ! $title_posted && ! $description_posted ) {
			return;
		}
		if ( HomepageMetadata::isStaticPage( $postId ) ) {
			self::rememberNotice( 'homepage' );
			return;
		}
		$params = array();
		if ( $title_posted ) {
			$params['title'] = sanitize_text_field( wp_unslash( (string) $_POST['_seostudio_title'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified the nonce.
		}
		if ( $description_posted ) {
			$params['description'] = sanitize_textarea_field( wp_unslash( (string) $_POST['_seostudio_description'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified the nonce.
		}
		$error = ( new SeoValidator() )->validate( $params, (string) home_url( '/' ) );
		if ( null !== $error ) {
			self::rememberNotice( 'invalid' );
			return;
		}
		foreach ( array(
			'title'       => '_seostudio_title',
			'description' => '_seostudio_description',
		) as $field => $key ) {
			if ( array_key_exists( $field, $params ) ) {
				SeoMeta::store( $postId, $key, $params[ $field ] );
			}
		}
	}

	private static function saveDirect( int $postId ): void {
		$params = array();
		foreach ( array(
			'canonical'       => '_seostudio_canonical',
			'og_title'        => '_seostudio_og_title',
			'og_description'  => '_seostudio_og_description',
			'focus_keyphrase' => '_seostudio_focus_keyphrase',
			'video_url'       => '_seostudio_video_url',
		) as $field => $key ) {
			if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified the nonce.
				$params[ $field ] = sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified the nonce.
			}
		}
		if ( isset( $_POST['seostudio_robots_index'], $_POST['seostudio_robots_follow'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified the nonce.
			$existing         = get_post_meta( $postId, '_seostudio_robots', true );
			$robots           = is_array( $existing ) ? $existing : array();
			$robots['index']  = '1' === (string) wp_unslash( $_POST['seostudio_robots_index'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified the nonce.
			$robots['follow'] = '1' === (string) wp_unslash( $_POST['seostudio_robots_follow'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified the nonce.
			$params['robots'] = $robots;
		}
		if ( isset( $_POST['seostudio_is_news'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified the nonce.
			$params['is_news'] = '1' === (string) wp_unslash( $_POST['seostudio_is_news'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified the nonce.
		}
		foreach ( array(
			'seostudio_related_keyphrases' => 'related_keyphrases',
			'seostudio_synonyms'           => 'synonyms',
		) as $input => $field ) {
			if ( ! isset( $_POST[ $input ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified the nonce.
				continue;
			}
			$decoded = json_decode( wp_unslash( (string) $_POST[ $input ] ), true ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified the nonce.
			if ( ! is_array( $decoded ) ) {
				self::rememberNotice( 'invalid' );
				return;
			}
			$params[ $field ] = $decoded;
		}
		if ( array() === $params ) {
			return;
		}
		$error = ( new SeoValidator() )->validate( $params, (string) home_url( '/' ) );
		if ( null !== $error ) {
			self::rememberNotice( 'invalid' );
			return;
		}
		foreach ( SeoValidator::FIELDS as $field => $key ) {
			if ( ! array_key_exists( $field, $params ) ) {
				continue;
			}
			SeoMeta::store( $postId, $key, $params[ $field ] );
		}
	}

	private static function rememberNotice( string $notice ): void {
		$GLOBALS['seostudio_classic_notice'] = $notice;
	}

	private static function pullNotice(): string {
		$notice = isset( $GLOBALS['seostudio_classic_notice'] ) ? (string) $GLOBALS['seostudio_classic_notice'] : '';
		unset( $GLOBALS['seostudio_classic_notice'] );
		return $notice;
	}
}
