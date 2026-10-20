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
		$this->assertStringContainsString( 'Rows imported: 1.', implode( ' ', $result['notes'] ) );
		$this->assertStringContainsString( 'Rows with a usable page address: 0.', implode( ' ', $result['notes'] ) );
		$this->assertStringContainsString( 'Rows without a usable page address: 1.', implode( ' ', $result['notes'] ) );
	}

	public function testAPartialImportCountsRowsThatCannotBeAttached(): void {
		$result = ( new Priorities() )->rank(
			$this->architecture(
				array(
					array(
						'post_id' => 32,
						'title'   => 'Fire door fixture',
						'url'     => 'https://example.test/?p=32',
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
				array(
					'query'       => 'staff absence insurance',
					'page'        => '',
					'clicks'      => 1,
					'impressions' => 900,
				),
			)
		);

		$notes = implode( ' ', $result['notes'] );
		$this->assertStringContainsString( 'Rows imported: 2.', $notes );
		$this->assertStringContainsString( 'Rows with a usable page address: 1.', $notes );
		$this->assertStringContainsString( 'Rows without a usable page address: 1.', $notes );
		$this->assertSame( 'Fire door fixture', $result['items'][0]['title'] );
		$this->assertStringContainsString( '80', $result['items'][0]['reason'] );
		$this->assertStringNotContainsString( '900', $result['items'][0]['reason'] );
		$this->assertStringContainsString( 'No imported row names this address', $result['items'][1]['reason'] );
		$this->assertStringNotContainsString( '900', $result['items'][1]['reason'] );
	}

	public function testVerifiedNoindexPagesAreOneGroupedFinding(): void {
		$one = ( new Priorities() )->rank(
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
					'query'       => 'rank tracker',
					'page'        => 'https://example.test/quiet/',
					'clicks'      => 1,
					'impressions' => 10,
				),
			),
			1
		);
		$this->assertSame( 'Quiet page', $one['items'][0]['title'] );
		$grouped = $one['items'][ count( $one['items'] ) - 1 ];
		$this->assertSame( 'visibility', $grouped['kind'] );
		$this->assertSame( '1 page is hidden from search.', $grouped['title'] );
		$this->assertSame( array(), $grouped['pages'] );
		$this->assertStringContainsString( 'visibility=noindex', $grouped['href'] );
		$this->assertStringContainsString( 'intentional', $grouped['action'] );
		$this->assertStringNotContainsString( 'remove noindex', strtolower( $grouped['action'] . ' ' . $grouped['reason'] ) );
		$this->assertStringContainsString( 'content score is separate', $grouped['reason'] );

		$many = ( new Priorities() )->rank(
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
			array(),
			100
		);
		$titles = array_column( $many['items'], 'title' );
		$this->assertSame( 'Quiet page', $titles[0] );
		$this->assertSame( '100 pages are hidden from search.', $titles[ count( $titles ) - 1 ] );
		$this->assertCount( 1, array_filter( $many['items'], static fn ( array $item ): bool => 'visibility' === $item['kind'] ) );
		$this->assertLessThanOrEqual( 40, count( $many['items'] ) );

		$none = ( new Priorities() )->rank( $this->architecture( array() ), array( 'groups' => array() ), array(), 0 );
		$this->assertNotContains( 'visibility', array_column( $none['items'], 'kind' ) );
	}

	public function testAGroupedNoindexFindingDoesNotFillTheFortyItemList(): void {
		$orphans = array();
		for ( $index = 0; $index < 45; $index++ ) {
			$orphans[] = array(
				'post_id' => $index + 1,
				'title'   => 'Orphan ' . $index,
				'url'     => 'https://example.test/orphan-' . $index . '/',
			);
		}
		$result = ( new Priorities() )->rank( $this->architecture( $orphans ), array( 'groups' => array() ), array(), 100 );
		$this->assertCount( 40, $result['items'] );
		$this->assertTrue( $result['limited'] );
		$this->assertSame( 'Orphan 0', $result['items'][0]['title'] );
		$this->assertSame( '100 pages are hidden from search.', $result['items'][39]['title'] );
		$this->assertSame( array(), $result['items'][39]['pages'] );
	}

	public function testPermittedFindingsAreChosenBeforeTheDisplayLimit(): void {
		$items = array();
		for ( $index = 1; $index <= 45; $index++ ) {
			$items[] = array(
				'priority' => 3,
				'kind'     => 'page',
				'title'    => 'Other ' . $index,
				'action'   => 'Review the link graph.',
				'reason'   => 'Stored graph.',
				'pages'    => array(
					array(
						'post_id' => 1000 + $index,
						'title'   => 'Other ' . $index,
						'url'     => 'https://example.test/other/' . $index . '/',
					),
				),
			);
		}
		$items[] = array(
			'priority' => 4,
			'kind'     => 'overlap',
			'title'    => 'shared phrase',
			'action'   => 'Compare what these pages are for.',
			'reason'   => 'These pages use the same target phrase.',
			'pages'    => array(
				array(
					'post_id' => 7,
					'title'   => 'Mine',
					'url'     => 'https://example.test/mine/',
				),
				array(
					'post_id' => 8,
					'title'   => 'Secret other title',
					'url'     => 'https://example.test/secret/',
				),
			),
		);
		$items[] = array(
			'priority' => 2,
			'kind'     => 'unresolved',
			'title'    => 'https://example.test/from-other/',
			'action'   => 'Review this internal address.',
			'reason'   => 'This internal address appears from Secret other title.',
			'pages'    => array(
				array(
					'post_id' => 8,
					'title'   => 'Secret other title',
					'url'     => 'https://example.test/secret/',
				),
			),
		);
		$items[] = array(
			'priority' => 4,
			'kind'     => 'search',
			'title'    => 'Import a Search Console file',
			'action'   => 'Import a performance export.',
			'reason'   => 'No file.',
			'pages'    => array(),
		);

		$priorities = new Priorities();
		$visible    = $priorities->actionable(
			$items,
			false,
			static function ( int $id ): bool {
				return 7 === $id;
			}
		);
		$this->assertCount( 1, $visible );
		$this->assertSame( 'shared phrase', $visible[0]['title'] );
		$this->assertSame( 'Mine', $visible[0]['pages'][0]['title'] );
		$this->assertTrue( $visible[0]['pages'][1]['redacted'] );
		$this->assertSame( '', $visible[0]['pages'][1]['url'] );
		$encoded = (string) json_encode( $visible );
		$this->assertStringNotContainsString( 'Secret other title', $encoded );
		$this->assertStringNotContainsString( 'example.test/secret', $encoded );
		$this->assertStringNotContainsString( 'from-other', $encoded );
		$this->assertStringNotContainsString( 'Import a Search Console', $encoded );
		$this->assertSame( 'shared phrase', array_slice( $visible, 0, 40 )[0]['title'] );

		$everything = $priorities->actionable(
			$items,
			true,
			static function (): bool {
				return false;
			}
		);
		$this->assertGreaterThan( 40, count( $everything ) );
		$this->assertStringContainsString( 'Secret other title', (string) json_encode( $everything ) );
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
