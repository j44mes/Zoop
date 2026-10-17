<?php
/**
 * Front-end tags printed only in Owner mode.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Output;

use SEOStudio\Core\Settings;
use SEOStudio\Integrations\Detector;

/**
 * Suspends itself while another SEO plugin is active. Nothing is deleted.
 */
final class HeadOutput {
	public function __construct(
		private readonly Detector $detector = new Detector(),
		private readonly TitleFilter $titles = new TitleFilter(),
		private readonly RobotsFilter $robots = new RobotsFilter(),
		private readonly SocialTags $social = new SocialTags(),
		private readonly SchemaGraph $schema = new SchemaGraph()
	) {}

	public static function register(): void {
		static $booted = false;
		if ( $booted ) {
			return;
		}
		$booted = true;

		$output = new self();
		add_filter( 'pre_get_document_title', array( $output, 'documentTitle' ) );
		add_filter( 'wp_robots', array( $output, 'robots' ) );
		add_action( 'wp_head', array( $output, 'prepare' ), 0 );
		add_action( 'wp_head', array( $output, 'render' ), 1 );
	}

	public function publishing(): bool {
		$settings = Settings::all();
		return 'owner' === (string) ( $settings['mode'] ?? 'analysis' ) && null === $this->detector->first();
	}

	public function documentTitle( string $title ): string {
		if ( ! $this->publishing() ) {
			return $title;
		}

		return $this->titles->filter( $title );
	}

	/**
	 * Apply the post robots override.
	 *
	 * @param array<string, bool|string> $robots
	 * @return array<string, bool|string>
	 */
	public function robots( array $robots ): array {
		if ( ! $this->publishing() ) {
			return $robots;
		}

		return $this->robots->filter( $robots );
	}

	public function prepare(): void {
		if ( ! $this->publishing() ) {
			return;
		}

		remove_action( 'wp_head', 'rel_canonical' );
	}

	public function render(): void {
		if ( ! $this->publishing() ) {
			return;
		}

		$page = $this->page();
		if ( null === $page ) {
			return;
		}

		if ( '' !== $page['description'] ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $page['description'] ) );
		}
		if ( '' !== $page['url'] ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $page['url'] ) );
		}
		$this->social->render( $page );
		$json = $this->schema->encode( $this->schema->nodes( $page['schema'] ) );
		echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX_TAG escapes tags. The string is not HTML.
	}

	/**
	 * Resolve the front page or singular post for head output.
	 *
	 * A static front page is singular. It still uses the homepage description and graph.
	 *
	 * @return array{description: string, url: string, title: string, social_title: string, social_description: string, home: bool, image: array<string, mixed>, schema: array<string, mixed>}|null
	 */
	private function page(): ?array {
		$settings = Settings::all();
		$home     = is_front_page();
		$image    = $this->image( (int) ( $settings['og_default_image'] ?? 0 ) );

		if ( $home ) {
			$description = $this->titles->expand( 0, (string) ( $settings['home_description'] ?? '' ) );
			$title       = $this->titles->expand( 0, (string) ( $settings['home_title'] ?? '' ) );
			$url         = (string) home_url( '/' );
			return array(
				'description'        => $description,
				'url'                => $url,
				'title'              => '' === $title ? wp_strip_all_tags( get_bloginfo( 'name' ) ) : $title,
				'social_title'       => '',
				'social_description' => '',
				'home'               => true,
				'image'              => $image,
				'schema'             => array(
					'home'      => true,
					'site_name' => wp_strip_all_tags( get_bloginfo( 'name' ) ),
					'site_url'  => $url,
					'local'     => $settings['local_business'] ?? array(),
				),
			);
		}

		if ( ! is_singular() ) {
			return null;
		}

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		$description = get_post_meta( $post->ID, '_seostudio_description', true );
		$description = is_string( $description ) && '' !== trim( $description )
			? $this->titles->expand( $post->ID, $description )
			: wp_strip_all_tags( get_the_excerpt( $post ) );
		$title       = get_post_meta( $post->ID, '_seostudio_title', true );
		$title       = is_string( $title ) && '' !== trim( $title )
			? $this->titles->expand( $post->ID, $title )
			: wp_strip_all_tags( get_the_title( $post ) );
		$canonical   = get_post_meta( $post->ID, '_seostudio_canonical', true );
		$url         = is_string( $canonical ) && '' !== trim( $canonical ) ? $canonical : (string) get_permalink( $post );
		$override    = (int) get_post_meta( $post->ID, '_seostudio_og_image', true );
		if ( $override > 0 ) {
			$image = $this->image( $override );
		}
		$news               = get_post_meta( $post->ID, '_seostudio_is_news', true );
		$video              = get_post_meta( $post->ID, '_seostudio_video_url', true );
		$social_title       = $this->socialText( $post->ID, '_seostudio_og_title' );
		$social_description = $this->socialText( $post->ID, '_seostudio_og_description' );

		return array(
			'description'        => $description,
			'url'                => $url,
			'title'              => $title,
			'social_title'       => $social_title,
			'social_description' => $social_description,
			'home'               => false,
			'image'              => $image,
			'schema'             => array(
				'home'           => false,
				'kind'           => $post->post_type,
				'site_name'      => wp_strip_all_tags( get_bloginfo( 'name' ) ),
				'site_url'       => (string) home_url( '/' ),
				'title'          => $title,
				'description'    => $description,
				'url'            => $url,
				'is_news'        => in_array( $news, array( true, 1, '1', 'yes' ), true ),
				'video_url'      => is_string( $video ) ? $video : '',
				'image'          => (string) ( $image['url'] ?? '' ),
				'date_published' => (string) get_post_time( DATE_W3C, true, $post ),
				'date_modified'  => (string) get_post_modified_time( DATE_W3C, true, $post ),
				'author'         => wp_strip_all_tags( get_the_author_meta( 'display_name', (int) $post->post_author ) ),
			),
		);
	}

	/**
	 * Expanded social title or description. An empty value leaves the SEO field in place.
	 */
	private function socialText( int $postId, string $key ): string {
		$value = get_post_meta( $postId, $key, true );
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return '';
		}

		return $this->titles->expand( $postId, $value );
	}

	/**
	 * Attachment URL and dimensions for the social image.
	 *
	 * @return array{url: string, width: int, height: int}
	 */
	private function image( int $attachmentId ): array {
		if ( $attachmentId <= 0 ) {
			return array(
				'url'    => '',
				'width'  => 0,
				'height' => 0,
			);
		}

		$src = wp_get_attachment_image_src( $attachmentId, 'full' );
		if ( ! is_array( $src ) ) {
			return array(
				'url'    => '',
				'width'  => 0,
				'height' => 0,
			);
		}

		return array(
			'url'    => (string) ( $src[0] ?? '' ),
			'width'  => (int) ( $src[1] ?? 0 ),
			'height' => (int) ( $src[2] ?? 0 ),
		);
	}
}
