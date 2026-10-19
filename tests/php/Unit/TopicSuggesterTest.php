<?php
/**
 * Topic suggestion tests.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Topics\Suggester;

/**
 * Suggestions come from saved wording and link counts, and they remain replaceable.
 */
final class TopicSuggesterTest extends TestCase {
	public function testKeyphraseBeatsTheHeadingAndCommercialWordingWins(): void {
		$suggestion = ( new Suggester() )->suggest(
			array(
				'title'          => 'How to compare prices',
				'h1'             => 'A guide',
				'keyphrase'      => 'fire door installation',
				'related'        => array( 'fire door installation', 'HMO', 'certification' ),
				'synonyms'       => array( 'certified installer' ),
				'in_inventory'   => true,
				'contextual_out' => 4,
				'depth'          => 1,
				'parent_topic'   => 'Fire safety',
			)
		);

		$this->assertSame( 'fire door installation', $suggestion['primary_topic']['value'] );
		$this->assertStringContainsString( 'target phrase', $suggestion['primary_topic']['reason'] );
		$this->assertSame( 'commercial', $suggestion['search_intent']['value'] );
		$this->assertStringContainsString( 'not Search Console', $suggestion['search_intent']['reason'] );
		$this->assertSame( 'hub', $suggestion['page_role']['value'] );
		$this->assertSame( 'Fire safety', $suggestion['parent_topic']['value'] );
		$this->assertSame( array( 'HMO', 'certification', 'certified installer' ), $suggestion['supporting_entities']['value'] );
	}

	public function testMissingWordingAndADeepPageStayCautious(): void {
		$suggestion = ( new Suggester() )->suggest(
			array(
				'title'        => 'Rank tracker chart',
				'h1'           => '',
				'keyphrase'    => '',
				'in_inventory' => true,
				'depth'        => 4,
			)
		);

		$this->assertSame( 'Rank tracker chart', $suggestion['primary_topic']['value'] );
		$this->assertSame( 'unassigned', $suggestion['search_intent']['value'] );
		$this->assertSame( 'supporting', $suggestion['page_role']['value'] );
		$this->assertSame( '', $suggestion['parent_topic']['value'] );
		$this->assertSame( array(), $suggestion['supporting_entities']['value'] );
	}

	public function testUtilityAndUnpublishedPagesAreNotHubs(): void {
		$suggester = new Suggester();
		$contact   = $suggester->suggest(
			array(
				'title'          => 'Contact',
				'in_inventory'   => true,
				'contextual_out' => 5,
				'depth'          => 1,
			)
		);
		$draft     = $suggester->suggest(
			array(
				'keyphrase'    => 'how to choose a guide',
				'in_inventory' => false,
				'depth'        => 4,
			)
		);

		$this->assertSame( 'utility', $contact['page_role']['value'] );
		$this->assertSame( 'navigational', $contact['search_intent']['value'] );
		$this->assertSame( 'informational', $draft['search_intent']['value'] );
		$this->assertSame( 'unassigned', $draft['page_role']['value'] );
		$this->assertStringContainsString( 'published inventory', $draft['page_role']['reason'] );
	}
}
