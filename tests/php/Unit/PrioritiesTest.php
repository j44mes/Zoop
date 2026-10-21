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
	public function testHighOverlapOutranksAnUnresolvedLinkAndAQuietOrphan(): void {
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
						'level'  => 'high',
						'phrase' => 'fire door installation',
						'reason' => 'These pages use the same target phrase. A shared phrase is not proof that the pages compete.',
						'pages'  => array(
							array( 'post_id' => 1, 'title' => 'Guide' ),
							array( 'post_id' => 2, 'title' => 'Cost' ),
						),
					),
					array(
						'level'  => 'medium',
						'phrase' => 'fire safety',
						'reason' => 'These pages share a primary topic. A shared topic is not proof that the pages compete.',
						'pages'  => array(
							array( 'post_id' => 1, 'title' => 'Guide' ),
							array( 'post_id' => 3, 'title' => 'Rules' ),
						),
					),
					array(
						'level'  => 'low',
						'phrase' => 'door words',
						'reason' => 'Titles share words.',
						'pages'  => array(),
					),
				),
			),
			array()
		);

		$this->assertSame(
			array( 'fire door installation', 'https://example.test/missing/', 'Quiet page', 'fire safety', 'Import a Search Console file' ),
			array_column( $result['items'], 'title' )
		);
		$this->assertSame( array( 1, 2, 3, 3, 3 ), array_column( $result['items'], 'priority' ) );
		$this->assertStringContainsString( 'not proof', $result['items'][0]['reason'] );
		$this->assertStringContainsString( 'does not edit', $result['items'][0]['reason'] );
		$this->assertStringContainsString( 'did not request', $result['items'][1]['reason'] );
		$this->assertStringContainsString( 'not known', $result['items'][2]['reason'] );
		$this->assertStringNotContainsString( 'No imported query', $result['items'][2]['reason'] );
		$this->assertStringContainsString( 'not proof of harm', implode( ' ', $result['notes'] ) );
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
		$this->assertStringContainsString( 'not connected', $result['items'][0]['reason'] );
		$this->assertSame( 'Other page', $result['items'][1]['title'] );
		$this->assertSame( 3, $result['items'][1]['priority'] );
		$this->assertStringContainsString( 'No imported query', $result['items'][1]['reason'] );
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
			array( 'groups' => array( array( 'level' => 'insufficient', 'phrase' => '', 'reason' => 'No shared phrase.', 'pages' => array() ) ) ),
			array()
		);

		$this->assertNotContains( 'Hidden orphan', array_column( $result['items'], 'title' ) );
		$this->assertContains( 'One link', array_column( $result['items'], 'title' ) );
		$this->assertStringContainsString( 'stored analysis', implode( ' ', $result['notes'] ) );
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
