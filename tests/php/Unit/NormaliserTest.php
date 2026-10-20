<?php
/**
 * Normaliser tests.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Scoring\OnPage\Normaliser;

/**
 * Covers function-word removal and Unicode folding.
 */
final class NormaliserTest extends TestCase {
	public function testFunctionWordsAreRemovedFromTheKeyphrase(): void {
		$words = ( new Normaliser() )->contentWords( 'how to price a cleaning contract' );
		$this->assertSame( array( 'price', 'cleaning', 'contract' ), $words );
	}

	public function testAccentedKeyphraseMatchesADifferentlyCasedTitle(): void {
		$match = ( new Normaliser() )->matchField( 'Café près de moi', 'café près de moi' );
		$this->assertTrue( $match->entire );
		$this->assertSame( 1, $match->position );
	}

	public function testHyphenatedPathMatchesAdjacentWords(): void {
		$match = ( new Normaliser() )->matchField( '/rank-tracker', 'rank tracker' );
		$this->assertTrue( $match->entire );
		$this->assertSame( 1.0, $match->coverage );
	}

	public function testRegularPluralsMatchInBothDirections(): void {
		$normaliser = new Normaliser();

		$doors = $normaliser->matchField( '/fire-doors', 'fire door' );
		$this->assertTrue( $doors->entire );
		$this->assertSame( 1.0, $doors->coverage );
		$door = $normaliser->matchField( 'fire door', 'Fire-doors' );
		$this->assertTrue( $door->entire );

		$quotes = $normaliser->matchField( 'cleaning quotes', 'cleaning quote' );
		$this->assertTrue( $quotes->entire );
		$quote = $normaliser->matchField( 'CLEANING QUOTE', 'cleaning quotes' );
		$this->assertTrue( $quote->entire );
	}

	public function testUnrelatedAndAmbiguousPhrasesStaySeparate(): void {
		$normaliser = new Normaliser();

		$contract = $normaliser->matchField( 'cleaning contract', 'cleaning quote' );
		$this->assertFalse( $contract->entire );
		$this->assertLessThan( 1.0, $contract->coverage );

		$boundary = $normaliser->matchField( 'door fire safety', 'fire doors' );
		$this->assertFalse( $boundary->entire );

		$this->assertFalse( $normaliser->equivalent( 'new', 'news' ) );
		$this->assertFalse( $normaliser->equivalent( 'serie', 'series' ) );
		$this->assertFalse( $normaliser->equivalent( 'city', 'cities' ) );
		$this->assertFalse( $normaliser->equivalent( 'door', 'doorknob' ) );
		$this->assertSame( array( 'price', 'cleaning', 'contract' ), $normaliser->contentWords( 'how to price a cleaning contract' ) );
	}
}
