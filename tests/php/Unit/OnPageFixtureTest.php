<?php
/**
 * Rubric fixtures.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Content\PageModel;
use SEOStudio\Scoring\OnPage\Normaliser;
use SEOStudio\Scoring\OnPage\Scorer;

/**
 * The ten published on-page fixtures.
 */
final class OnPageFixtureTest extends TestCase {
	public function testFixtures(): void {
		$scorer = new Scorer();
		$files  = glob( dirname( __DIR__, 2 ) . '/fixtures/onpage/*.json' );
		$this->assertIsArray( $files );
		$this->assertCount( 10, $files );

		foreach ( $files as $file ) {
			$raw = file_get_contents( $file );
			$this->assertIsString( $raw );
			$data = json_decode( $raw, true );
			$this->assertIsArray( $data, $file );

			$page      = PageModel::fromArray( is_array( $data['page'] ?? null ) ? $data['page'] : array() );
			$keyphrase = (string) $data['keyphrase'];
			$score     = $scorer->score( $page, $keyphrase );
			$again     = $scorer->score( $page, $keyphrase );
			$third     = $scorer->score( $page, $keyphrase );
			$this->assertSame( $score->toArray(), $again->toArray(), (string) $data['name'] );
			$this->assertSame( $score->toArray(), $third->toArray(), (string) $data['name'] );

			if ( isset( $data['expect']['content_words'] ) && is_array( $data['expect']['content_words'] ) ) {
				$this->assertSame( $data['expect']['content_words'], ( new Normaliser() )->contentWords( $keyphrase ), (string) $data['name'] );
			}

			if ( isset( $data['expect']['earned'] ) ) {
				$this->assertSame( $data['expect']['earned'], $score->earned, (string) $data['name'] );
			}
			if ( isset( $data['expect']['denominator'] ) ) {
				$this->assertSame( $data['expect']['denominator'], $score->denominator, (string) $data['name'] );
			}
			if ( isset( $data['expect']['categories'] ) && is_array( $data['expect']['categories'] ) ) {
				foreach ( $data['expect']['categories'] as $category => $earned ) {
					$this->assertSame( $earned, $score->categories[ $category ]['earned'] ?? null, (string) $data['name'] . ' ' . $category );
				}
			}
			if ( isset( $data['expect']['assessments'] ) && is_array( $data['expect']['assessments'] ) ) {
				$by_id = array();
				foreach ( $score->assessments as $result ) {
					$by_id[ $result->id ] = $result;
				}
				foreach ( $data['expect']['assessments'] as $id => $expected ) {
					$this->assertArrayHasKey( $id, $by_id, (string) $data['name'] );
					if ( isset( $expected['earned'] ) ) {
						$this->assertSame( $expected['earned'], $by_id[ $id ]->earned, (string) $data['name'] . ' ' . $id );
					}
					if ( isset( $expected['status'] ) ) {
						$this->assertSame( $expected['status'], $by_id[ $id ]->status, (string) $data['name'] . ' ' . $id );
					}
				}
			}
		}
	}

	public function testAssessmentMaximumsSumToOneHundred(): void {
		$max = 0;
		foreach ( ( new Scorer() )->assessments() as $assessment ) {
			$max += $assessment->max();
		}
		$this->assertSame( 100, $max );
		$this->assertCount( 22, ( new Scorer() )->assessments() );
	}
}
