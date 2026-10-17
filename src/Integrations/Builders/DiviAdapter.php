<?php
/**
 * How Divi content is reached.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Integrations\Builders;

/**
 * Divi 4 shortcodes and Divi 5 blocks both live in post_content. The panel is the classic meta box.
 */
final class DiviAdapter {
	/**
	 * Report how this builder stores content.
	 *
	 * @return array{builder: string, detected: bool, version: string, reachable: string, panel: string}
	 */
	public function describe( string $content ): array {
		$blocks = str_contains( $content, '<!-- wp:divi/' );
		$codes  = str_contains( $content, '[et_pb_' );

		return array(
			'builder'   => 'divi',
			'detected'  => $blocks || $codes,
			'version'   => $blocks ? '5' : ( $codes ? '4' : '' ),
			'reachable' => 'content_render',
			'panel'     => 'metabox',
		);
	}
}
