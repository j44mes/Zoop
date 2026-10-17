<?php
/**
 * SEO field contract tests.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Rest\SeoValidator;

/**
 * One invalid value per field, matching the REST error field name.
 */
final class SeoValidatorTest extends TestCase {
	private const HOME = 'https://example.test/';

	public function testRejectsEachInvalidField(): void {
		$cases = array(
			'focus_keyphrase'    => str_repeat( 'a', 256 ),
			'title'              => str_repeat( 'a', 301 ),
			'description'        => str_repeat( 'a', 501 ),
			'canonical'          => 'https://evil.test/page',
			'related_keyphrases' => array(
				array( 'keyphrase' => 'one', 'synonyms' => array() ),
				array( 'keyphrase' => 'two', 'synonyms' => array() ),
				array( 'keyphrase' => 'three', 'synonyms' => array() ),
				array( 'keyphrase' => 'four', 'synonyms' => array() ),
				array( 'keyphrase' => 'five', 'synonyms' => array() ),
			),
			'synonyms'           => array( 'ok', 3 ),
			'robots'             => array( 'index' => 'yes' ),
			'og_title'           => str_repeat( 'a', 301 ),
			'og_description'     => str_repeat( 'a', 501 ),
			'og_image'           => -1,
			'cornerstone'        => 'yes',
			'content_selector'   => '<script>',
		);

		$validator = new SeoValidator();
		foreach ( $cases as $field => $value ) {
			$error = $validator->validate( array( $field => $value ), self::HOME );
			$this->assertIsArray( $error, $field . ' should be invalid' );
			$this->assertSame( $field, $error['field'] );
		}
	}

	public function testAcceptsASameHostCanonical(): void {
		$error = ( new SeoValidator() )->validate(
			array( 'canonical' => 'https://example.test/rank-tracker' ),
			self::HOME
		);
		$this->assertNull( $error );
	}
}
