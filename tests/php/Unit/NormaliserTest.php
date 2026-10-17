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
}
