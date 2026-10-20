<?php
/**
 * Internal-link graph tests.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Architecture\Graph;
use SEOStudio\Content\Extractor;

/**
 * Referring pages, occurrences, depth, orphans, and unresolved addresses.
 */
final class ArchitectureGraphTest extends TestCase {
	public function testLiveHeaderLinkIsTemplateAndContentLinkIsContextual(): void {
		$html  = '<html><body><header><a href="/nav">Nav</a></header><main><a href="/body">Body</a></main></body></html>';
		$live  = ( new Extractor() )->extract(
			$html,
			array(
				'render_mode' => 'live',
				'url'         => 'https://example.test/page',
			)
		);
		$kinds = array();
		foreach ( $live->links as $link ) {
			$kinds[ $link['href'] ] = $link['kind'];
		}

		$this->assertSame( 'template', $kinds['/nav'] );
		$this->assertSame( 'contextual', $kinds['/body'] );

		$content       = ( new Extractor() )->extract(
			$html,
			array(
				'render_mode' => 'content',
				'url'         => 'https://example.test/page',
			)
		);
		$content_kinds = array();
		foreach ( $content->links as $link ) {
			$content_kinds[ $link['href'] ] = $link['kind'];
		}
		$this->assertSame( 'template', $content_kinds['/nav'] );
		$this->assertSame( 'contextual', $content_kinds['/body'] );

		$body = ( new Extractor() )->extract(
			'<html><body><p><a href="/inside">Inside</a></p></body></html>',
			array(
				'render_mode' => 'content',
				'url'         => 'https://example.test/page',
			)
		);
		$this->assertSame( 'contextual', $body->links[0]['kind'] );
	}

	public function testCompleteCrawlSeparatesReferrersOccurrencesDepthAndOrphans(): void {
		$report = $this->report( true );

		$services = $this->find( $report['pages'], 'post:2' );
		$this->assertSame( 1, $services['contextual_referrers'] );
		$this->assertSame( array( 'post:1' ), $services['contextual_referrer_ids'] );
		$this->assertSame( 2, $services['contextual_occurrences'] );
		$this->assertSame( 1, $services['depth'] );
		$this->assertSame( 'weak', $services['status'] );

		$about = $this->find( $report['pages'], 'post:8' );
		$this->assertSame( 0, $about['contextual_referrers'] );
		$this->assertSame( 1, $about['template_referrers'] );
		$this->assertSame( 2, $about['template_occurrences'] );
		$this->assertSame( 'template_only', $about['status'] );
		$this->assertSame( 1, $about['depth'] );

		$deep = $this->find( $report['pages'], 'post:5' );
		$this->assertSame( 2, $deep['depth'] );

		$orphan = $this->find( $report['pages'], 'post:6' );
		$this->assertSame( 'orphan', $orphan['status'] );
		$this->assertNull( $orphan['depth'] );
		$this->assertSame( 1, $report['counts']['orphans'] );
		$this->assertSame( 'post:6', $report['orphans'][0]['id'] );

		$this->assertSame( 'front', $this->find( $report['pages'], 'post:1' )['status'] );
		$this->assertSame( 0, $this->find( $report['pages'], 'post:1' )['depth'] );
		$this->assertSame( 1, $report['counts']['unresolved'] );
		$this->assertSame( 'https://example.test/missing', $report['unresolved'][0]['href'] );
		$this->assertStringContainsString( 'does not request', implode( ' ', $report['notes'] ) );
	}

	public function testIncompleteCrawlHidesOrphans(): void {
		$report = $this->report( false );
		$pages  = $report['pages'];

		$this->assertFalse( $report['coverage']['complete'] );
		$this->assertNull( $report['counts']['orphans'] );
		$this->assertSame( array(), $report['orphans'] );
		$this->assertSame( 'unknown', $this->find( $pages, 'post:6' )['status'] );
		$this->assertSame( 'weak', $this->find( $pages, 'post:2' )['status'] );
		$this->assertNull( $report['counts']['unreachable'] );
		$notes = implode( ' ', $report['notes'] );
		$this->assertStringContainsString( 'not counted yet', $notes );
		$this->assertStringContainsString( 'not been analysed', $notes );
		$this->assertStringNotContainsString( 'modelled entry points', $notes );
	}

	public function testContentOnlyGapDoesNotClaimPagesAreUnanalysed(): void {
		$pages = array(
			$this->page( 'post:1', '/', array(), true ),
			$this->page( 'post:2', '/services', array(), true ),
		);
		$result = ( new Graph() )->evaluate(
			$pages,
			array(),
			array(
				'home'              => 'https://example.test/',
				'front_id'          => 'post:1',
				'coverage_complete' => false,
				'missing'           => 25,
				'coverage_reasons'  => array(
					'unanalysed'         => 0,
					'content_only'       => 26,
					'stale_links'        => 0,
					'navigation_limited' => false,
					'navigation_note'    => '',
				),
			)
		);
		$report = $result['report'];
		$text   = implode( ' ', $report['notes'] ) . ' ' . $report['depth_explanation'] . ' ' . $report['orphan_explanation'];

		$this->assertSame( 2, $report['counts']['analysed'] );
		$this->assertNull( $report['counts']['orphans'] );
		$this->assertNull( $report['counts']['unreachable'] );
		$this->assertSame( 'front', $result['pages']['post:1']['status'] );
		$this->assertSame( 'unknown', $result['pages']['post:2']['status'] );
		$this->assertNotSame( 'orphan', $result['pages']['post:2']['status'] );
		$this->assertStringContainsString( 'without published-link verification', $text );
		$this->assertStringContainsString( 'Depth is not known yet', $report['depth_explanation'] );
		$this->assertStringNotContainsString( 'not been analysed', $text );
		$this->assertStringNotContainsString( "haven't been analysed", $text );
	}

	public function testCompleteCoverageLabelsMissingPathsWithoutClaimingUnreachability(): void {
		$report = $this->report( true );
		$notes  = implode( ' ', $report['notes'] );

		$this->assertIsInt( $report['counts']['unreachable'] );
		$this->assertGreaterThan( 0, $report['counts']['unreachable'] );
		$this->assertSame( 'orphan', $this->find( $report['pages'], 'post:6' )['status'] );
		$this->assertSame( 'template_only', $this->find( $report['pages'], 'post:8' )['status'] );
		$this->assertNotSame( 'unknown', $this->find( $report['pages'], 'post:8' )['status'] );
		$this->assertStringContainsString( 'modelled entry points', $notes );
		$this->assertStringContainsString( 'search engines', $report['depth_explanation'] );
		$this->assertStringNotContainsString( 'not been analysed', $notes );
	}

	public function testSchemeSlashAndTrackingStillMatchInventory(): void {
		$graph  = new Graph();
		$result = $graph->evaluate(
			array(
				$this->page( 'post:1', '/', array() ),
				$this->page(
					'post:2',
					'/services',
					array(
						array(
							'href' => 'http://example.test/services/',
							'kind' => 'contextual',
						),
						array(
							'href' => 'http://example.test/pricing/',
							'kind' => 'contextual',
						),
						array(
							'href' => 'https://example.test/pricing?utm_source=ad',
							'kind' => 'contextual',
						),
						array(
							'href' => 'mailto:editor@example.test',
							'kind' => 'contextual',
						),
						array(
							'href' => 'https://example.test/wp-admin/edit.php',
							'kind' => 'contextual',
						),
						array(
							'href' => 'https://example.test/zoop-site/wp-login.php',
							'kind' => 'contextual',
						),
						array(
							'href' => 'https://other.test/away',
							'kind' => 'contextual',
						),
					)
				),
				$this->page(
					'post:10',
					'/blog/post',
					array(
						array(
							'href' => '../pricing',
							'kind' => 'contextual',
						),
					)
				),
				$this->page( 'post:9', '/pricing', array() ),
			),
			array(),
			array(
				'home'              => 'https://example.test/',
				'front_id'          => 'post:1',
				'coverage_complete' => true,
				'missing'           => 0,
			)
		);
		$pages  = $result['pages'];

		$this->assertSame( 'orphan', $pages['post:2']['status'] );
		$this->assertSame( 1, $pages['post:2']['contextual_out'] );
		$this->assertSame( 2, $pages['post:9']['contextual_referrers'] );
		$this->assertSame( 3, $pages['post:9']['contextual_occurrences'] );
		$this->assertSame( 'linked', $pages['post:9']['status'] );
		$this->assertSame( 0, $result['report']['counts']['unresolved'] );
	}

	public function testBlogIndexUsesNavigationForDepth(): void {
		$result = ( new Graph() )->evaluate(
			array(
				$this->page(
					'post:2',
					'/services',
					array(
						array(
							'href' => '/nested',
							'kind' => 'contextual',
						),
					)
				),
				$this->page( 'post:3', '/nested', array() ),
			),
			array(
				array(
					'href'   => '/services',
					'anchor' => 'Services',
				),
			),
			array(
				'home'              => 'https://example.test/',
				'front_id'          => '',
				'coverage_complete' => true,
				'missing'           => 0,
			)
		);

		$this->assertSame( 'blog', $result['report']['front']['kind'] );
		$this->assertSame( 1, $result['pages']['post:2']['depth'] );
		$this->assertSame( 2, $result['pages']['post:3']['depth'] );
		$this->assertStringContainsString( 'blog index', implode( ' ', $result['report']['notes'] ) );
	}

	/**
	 * Return one page from the evaluated graph.
	 *
	 * @param array<string, array<string, mixed>> $pages
	 * @return array<string, mixed>
	 */
	private function find( array $pages, string $id ): array {
		$this->assertArrayHasKey( $id, $pages );
		return $pages[ $id ];
	}

	/**
	 * Evaluate the shared fixture and return the report with every page attached.
	 *
	 * @return array<string, mixed>
	 */
	private function report( bool $complete ): array {
		$pages = array(
			$this->page(
				'post:1',
				'/',
				array(
					array(
						'href' => '/services',
						'kind' => 'contextual',
					),
					array(
						'href' => '/services',
						'kind' => 'contextual',
					),
					array(
						'href' => '/blog',
						'kind' => 'contextual',
					),
					array(
						'href' => '/missing',
						'kind' => 'contextual',
					),
					array(
						'href' => 'https://other.test/out',
						'kind' => 'contextual',
					),
					array(
						'href' => '/',
						'kind' => 'contextual',
					),
				)
			),
			$this->page(
				'post:2',
				'/services',
				array(
					array(
						'href' => '/deep',
						'kind' => 'contextual',
					),
				)
			),
			$this->page( 'post:3', '/blog', array() ),
			$this->page( 'post:4', '/contact', array() ),
			$this->page( 'post:5', '/deep', array() ),
			$this->page( 'post:6', '/orphan', array(), true ),
			$this->page( 'post:8', '/about', array() ),
		);
		if ( ! $complete ) {
			$pages[5]['analysed'] = false;
		}

		$result = ( new Graph() )->evaluate(
			$pages,
			array(
				array(
					'href'   => '/about',
					'anchor' => 'About',
				),
				array(
					'href'   => '/about/',
					'anchor' => 'About again',
				),
				array(
					'href'   => '/contact',
					'anchor' => 'Contact',
				),
			),
			array(
				'home'              => 'https://example.test/',
				'front_id'          => 'post:1',
				'coverage_complete' => $complete,
				'missing'           => $complete ? 0 : 1,
			)
		);

		$this->assertArrayHasKey( 'pages', $result );
		$report          = $result['report'];
		$report['pages'] = $result['pages'];
		return $report;
	}

	/**
	 * One inventory page on example.test.
	 *
	 * @param array<int, array{href: string, kind?: string, anchor?: string}> $links
	 * @return array<string, mixed>
	 */
	private function page( string $id, string $path, array $links, bool $analysed = true ): array {
		return array(
			'id'       => $id,
			'post_id'  => (int) substr( $id, 5 ),
			'title'    => trim( $path, '/' ) === '' ? 'Home' : trim( $path, '/' ),
			'url'      => 'https://example.test' . ( '/' === $path ? '/' : $path ),
			'analysed' => $analysed,
			'links'    => $links,
		);
	}
}
