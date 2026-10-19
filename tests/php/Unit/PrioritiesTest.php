<?php
/**
 * Priority order tests.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Recommendations\Priorities;

/**
 * Recommendations order stored evidence and do not edit pages.
 */
final class PrioritiesTest extends TestCase {
	public function testPhraseOverlapFollowsStructuralProblems(): void {
		$result = ( new Priorities() )->rank(
			array(
				'coverage'   => array( 'complete' => true ),
				'unresolved' => array(
					array(
						'from_id'     => 'post:9',
						'from_title'  => 'Services',
						'href'        => 'https://example.test/missing/',
						'occurrences' => 2,
					),
				),
				'orphans'    => array(
					array(
						'post_id' => 4,
						'title'   => 'Quiet page',
						'url'     => 'https://example.test/quiet/',
					),
				),
				'weak'       => array(),
			),
			array(
				'groups' => array(
					array(
						'level'                  => 'investigation',
						'observation'            => 'phrase',
						'observation_confidence' => 'high',
						'conclusion'             => 'investigation',
						'conclusion_confidence'  => 'insufficient',
						'phrase'                 => 'fire door installation',
						'reason'                 => 'These pages use the same target phrase. It does not prove the pages compete.',
						'pages'                  => array(
							array(
								'post_id' => 1,
								'title'   => 'Guide',
							),
							array(
								'post_id' => 2,
								'title'   => 'Cost',
							),
						),
					),
					array(
						'level'                  => 'investigation',
						'observation'            => 'topic',
						'observation_confidence' => 'high',
						'conclusion'             => 'investigation',
						'conclusion_confidence'  => 'insufficient',
						'phrase'                 => 'fire safety',
						'reason'                 => 'These pages share a primary topic. It does not prove the pages compete.',
						'pages'                  => array(
							array(
								'post_id' => 1,
								'title'   => 'Guide',
							),
							array(
								'post_id' => 3,
								'title'   => 'Rules',
							),
						),
					),
					array(
						'level'       => 'investigation',
						'observation' => 'title',
						'phrase'      => 'door words',
						'reason'      => 'Titles share words.',
						'pages'       => array(),
					),
				),
			),
			array()
		);

		$this->assertSame(
			array( 'https://example.test/missing/', 'Quiet page', 'fire door installation', 'fire safety', 'Import a Search Console file' ),
			array_column( $result['items'], 'title' )
		);
		$this->assertSame( array( 2, 3, 4, 4, 4 ), array_column( $result['items'], 'priority' ) );
		$this->assertSame( 'insufficient', $result['items'][2]['conclusion_confidence'] );
		$this->assertStringContainsString( 'not prove', $result['items'][2]['reason'] );
		$this->assertStringContainsString( 'does not edit', $result['items'][2]['reason'] );
		$this->assertStringContainsString( 'did not request', $result['items'][0]['reason'] );
		$this->assertStringContainsString( 'not known', $result['items'][1]['reason'] );
		$this->assertStringNotContainsString( 'No imported query', $result['items'][1]['reason'] );
		$this->assertNotContains( 1, array_column( $result['items'], 'priority' ) );
		$this->assertStringContainsString( 'not an urgent', implode( ' ', $result['notes'] ) );
	}

	public function testImportedImpressionsRaiseAnOrphanAndMatchEitherScheme(): void {
		$result = ( new Priorities() )->rank(
			$this->architecture(
				array(
					array(
						'post_id' => 32,
						'title'   => 'Fire door fixture',
						'url'     => 'http://example.test/?p=32',
					),
					array(
						'post_id' => 8,
						'title'   => 'Other page',
						'url'     => 'https://example.test/other/',
					),
				)
			),
			array( 'groups' => array() ),
			array(
				array(
					'query'       => 'fire door installation',
					'page'        => 'https://example.test/?p=32',
					'clicks'      => 4,
					'impressions' => 80,
				),
			)
		);

		$this->assertSame( 'Fire door fixture', $result['items'][0]['title'] );
		$this->assertSame( 2, $result['items'][0]['priority'] );
		$this->assertStringContainsString( '80', $result['items'][0]['reason'] );
		$this->assertStringContainsString( 'fire door installation', $result['items'][0]['reason'] );
		$this->assertStringContainsString( 'not verified commercial value', $result['items'][0]['reason'] );
		$this->assertStringContainsString( 'not evidence of lost traffic', $result['items'][0]['reason'] );
		$this->assertStringContainsString( 'not connected', $result['items'][0]['reason'] );
		$this->assertSame( 'Other page', $result['items'][1]['title'] );
		$this->assertSame( 3, $result['items'][1]['priority'] );
		$this->assertStringContainsString( 'No imported row names this address', $result['items'][1]['reason'] );
		$this->assertNotContains( 'Import a Search Console file', array_column( $result['items'], 'title' ) );
	}

	public function testAnIncompleteCrawlDoesNotInventOrphans(): void {
		$result = ( new Priorities() )->rank(
			array(
				'coverage'   => array( 'complete' => false ),
				'orphans'    => array(
					array(
						'post_id' => 4,
						'title'   => 'Hidden orphan',
						'url'     => 'https://example.test/hidden/',
					),
				),
				'weak'       => array(
					array(
						'post_id' => 5,
						'title'   => 'One link',
						'url'     => 'https://example.test/one/',
					),
				),
				'unresolved' => array(),
			),
			array(
				'groups' => array(
					array(
						'level'  => 'insufficient',
						'phrase' => '',
						'reason' => 'No shared phrase.',
						'pages'  => array(),
					),
				),
			),
			array()
		);

		$this->assertNotContains( 'Hidden orphan', array_column( $result['items'], 'title' ) );
		$this->assertContains( 'One link', array_column( $result['items'], 'title' ) );
		$this->assertStringContainsString( 'stored analysis', implode( ' ', $result['notes'] ) );
	}

	public function testAQueryOnlyImportDoesNotAttachImpressionsToAPage(): void {
		$result = ( new Priorities() )->rank(
			$this->architecture(
				array(
					array(
						'post_id' => 4,
						'title'   => 'Quiet page',
						'url'     => 'https://example.test/quiet/',
					),
				)
			),
			array( 'groups' => array() ),
			array(
				array(
					'query'       => 'staff absence insurance',
					'page'        => '',
					'clicks'      => 4,
					'impressions' => 700,
				),
			)
		);

		$this->assertSame( 'Quiet page', $result['items'][0]['title'] );
		$this->assertSame( 3, $result['items'][0]['priority'] );
		$this->assertStringContainsString( 'no page addresses', $result['items'][0]['reason'] );
		$this->assertStringNotContainsString( '700', $result['items'][0]['reason'] );
		$this->assertSame( 'The Search Console file has no page addresses', $result['items'][1]['title'] );
		$this->assertStringContainsString( 'not verified commercial value', implode( ' ', $result['notes'] ) );
	}

	/**
	 * Architecture fixture with a complete crawl.
	 *
	 * @param list<array<string, mixed>> $orphans Orphan rows.
	 * @return array<string, mixed>
	 */
	private function architecture( array $orphans ): array {
		return array(
			'coverage'   => array( 'complete' => true ),
			'orphans'    => $orphans,
			'weak'       => array(),
			'unresolved' => array(),
		);
	}
}
