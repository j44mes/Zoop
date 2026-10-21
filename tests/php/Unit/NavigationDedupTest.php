<?php
/**
 * Menu copies do not inflate referring pages.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Architecture\Graph;

/**
 * Shared menu evidence stays one edge. Page-level links stay separate.
 */
final class NavigationDedupTest extends TestCase {
	public function testMenuObservedOnThreePagesIsOneReferrer(): void {
		$pages = array(
			$this->page( 'post:1', '/', array() ),
			$this->page( 'post:2', '/about', array() ),
			$this->page(
				'post:3',
				'/a',
				array(
					array(
						'href' => '/about',
						'kind' => 'template',
					),
				)
			),
			$this->page(
				'post:4',
				'/b',
				array(
					array(
						'href' => '/about',
						'kind' => 'template',
					),
				)
			),
			$this->page(
				'post:5',
				'/c',
				array(
					array(
						'href' => '/about',
						'kind' => 'template',
					),
					array(
						'href' => '/about',
						'kind' => 'contextual',
					),
					array(
						'href' => '/about',
						'kind' => 'contextual',
					),
				)
			),
		);
		$result = ( new Graph() )->evaluate(
			$pages,
			array(
				array(
					'href'     => '/about',
					'anchor'   => 'About',
					'kind'     => 'navigation',
					'verified' => false,
				),
			),
			array(
				'home'              => 'https://example.test/',
				'front_id'          => 'post:1',
				'coverage_complete' => true,
				'missing'           => 0,
			)
		);
		$about = $result['pages']['post:2'];
		$this->assertSame( 1, $about['navigation_referrers'] );
		$this->assertSame( 1, $about['template_referrers'] );
		$this->assertSame( 1, $about['template_occurrences'] );
		$this->assertSame( 1, $about['contextual_referrers'] );
		$this->assertSame( 2, $about['contextual_occurrences'] );
		$this->assertSame( 'weak', $about['status'] );
	}

	public function testFooterOnlyLinkIsTemplateNotOrphan(): void {
		$result = ( new Graph() )->evaluate(
			array(
				$this->page( 'post:1', '/', array() ),
				$this->page( 'post:2', '/about', array() ),
				$this->page(
					'post:3',
					'/legal',
					array(
						array(
							'href' => '/about',
							'kind' => 'template',
						),
					)
				),
			),
			array(),
			array(
				'home'              => 'https://example.test/',
				'front_id'          => 'post:1',
				'coverage_complete' => true,
				'missing'           => 0,
			)
		);
		$about = $result['pages']['post:2'];
		$this->assertSame( 'template_only', $about['status'] );
		$this->assertSame( 1, $about['template_referrers'] );
		$this->assertSame( 0, $about['contextual_referrers'] );
	}

	public function testUnobservedMenuDoesNotCountAsAPublishedLink(): void {
		$result = ( new Graph() )->evaluate(
			array(
				$this->page( 'post:1', '/', array() ),
				$this->page( 'post:2', '/about', array() ),
			),
			array(
				array(
					'href'     => '/about',
					'kind'     => 'navigation',
					'verified' => false,
				),
			),
			array(
				'home'              => 'https://example.test/',
				'front_id'          => 'post:1',
				'coverage_complete' => true,
				'missing'           => 0,
			)
		);
		$this->assertSame( 'orphan', $result['pages']['post:2']['status'] );
		$this->assertSame( 'high', $result['pages']['post:2']['confidence'] );
	}

	public function testStaleLinksAreWithheld(): void {
		$result = ( new Graph() )->evaluate(
			array(
				$this->page(
					'post:1',
					'/',
					array(
						array(
							'href' => '/about',
							'kind' => 'contextual',
						),
					),
					true,
					false
				),
				$this->page( 'post:2', '/about', array() ),
			),
			array(),
			array(
				'home'              => 'https://example.test/',
				'front_id'          => 'post:1',
				'coverage_complete' => false,
				'missing'           => 1,
			)
		);
		$this->assertSame( 0, $result['pages']['post:2']['contextual_referrers'] );
		$this->assertSame( 'unknown', $result['pages']['post:2']['status'] );
		$this->assertSame( 'Not yet verified.', $result['pages']['post:2']['observation'] );
	}

	/**
	 * @param array<int, array<string, mixed>> $links
	 * @return array<string, mixed>
	 */
	private function page( string $id, string $url, array $links, bool $analysed = true, bool $fresh = true ): array {
		return array(
			'id'         => $id,
			'post_id'    => (int) substr( $id, 5 ),
			'title'      => $url,
			'url'        => 'https://example.test' . $url,
			'analysed'   => $analysed,
			'link_fresh' => $fresh,
			'links'      => $links,
		);
	}
}
