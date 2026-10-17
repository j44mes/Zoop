<?php
/**
 * Page model serialisation tests.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Content\PageModel;

/**
 * JSON encoding must keep every field. The database round trip is an integration test.
 */
final class PageModelRoundTripTest extends TestCase {
	public function testArrayRoundTripIsDeeplyEqual(): void {
		$model = PageModel::fromArray( $this->payload() );
		$again = PageModel::fromArray( json_decode( (string) json_encode( $model->toArray() ), true ) );
		$this->assertSame( $model->toArray(), $again->toArray() );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function payload(): array {
		return array(
			'url'                   => 'https://example.test/rank-tracker',
			'slug'                  => 'rank-tracker',
			'title_tag'             => 'Rank tracker',
			'meta_description'      => 'Track rankings.',
			'canonical'             => 'https://example.test/rank-tracker',
			'robots'                => 'index, follow',
			'h1'                    => array( 'Rank tracker' ),
			'headings'              => array(
				array(
					'level' => 2,
					'text'  => 'Method',
					'order' => 1,
				),
			),
			'body_text'             => 'A full paragraph about rankings.',
			'word_count'            => 5,
			'paragraphs'            => array( 'A full paragraph about rankings.' ),
			'sentences'             => array( 'A full paragraph about rankings.' ),
			'links'                 => array(
				array(
					'href'     => 'https://example.test/other',
					'anchor'   => 'Other',
					'internal' => true,
					'nofollow' => false,
				),
			),
			'images'                => array(
				array(
					'src'     => 'https://example.test/chart.png',
					'alt'     => 'Chart',
					'in_body' => true,
				),
			),
			'has_external_css'      => true,
			'html_bytes'            => 1200,
			'visible_text_bytes'    => 40,
			'is_https'              => true,
			'lang'                  => 'en',
			'render_mode'           => 'live',
			'content_selector_used' => 'main',
			'rendered_at'           => '2026-10-17T00:00:00+00:00',
			'page_type'             => 'post',
		);
	}
}
