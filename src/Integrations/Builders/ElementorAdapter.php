<?php
/**
 * How Elementor content is reached.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Integrations\Builders;

/**
 * Elementor stores JSON in post meta and a flattened copy in post_content.
 */
final class ElementorAdapter {
	/**
	 * Report how this builder stores content.
	 *
	 * @return array{builder: string, detected: bool, reachable: string, panel: string}
	 */
	public function describe( string $content, string $elementorData = '' ): array {
		unset( $content );
		$detected = '' !== trim( $elementorData );

		return array(
			'builder'   => 'elementor',
			'detected'  => $detected,
			'reachable' => $detected ? 'flattened_post_content' : 'content_render',
			'panel'     => 'metabox',
		);
	}

	/**
	 * Read the meta WordPress stored for a post.
	 *
	 * @return array{builder: string, detected: bool, reachable: string, panel: string}
	 */
	public function describePost( int $postId ): array {
		$data = get_post_meta( $postId, '_elementor_data', true );
		$post = get_post( $postId );

		return $this->describe(
			$post instanceof \WP_Post ? $post->post_content : '',
			is_string( $data ) ? $data : ''
		);
	}
}
