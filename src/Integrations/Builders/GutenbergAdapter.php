<?php
/**
 * How Gutenberg content is reached.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Integrations\Builders;

/**
 * Block markup in post_content is rendered by the_content. The sidebar is the panel.
 */
final class GutenbergAdapter {
	/**
	 * Report how this builder stores content.
	 *
	 * @return array{builder: string, detected: bool, reachable: string, panel: string}
	 */
	public function describe( string $content ): array {
		$detected = function_exists( 'has_blocks' ) ? has_blocks( $content ) : str_contains( $content, '<!-- wp:' );

		return array(
			'builder'   => 'gutenberg',
			'detected'  => $detected,
			'reachable' => 'content_render',
			'panel'     => 'sidebar',
		);
	}
}
