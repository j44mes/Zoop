<?php
/**
 * JSON-LD graph tests.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Output\SchemaGraph;

/**
 * V1 nodes plus the V2 LocalBusiness, VideoObject, and NewsArticle types.
 */
final class SchemaGraphTest extends TestCase {
	public function testHomePrintsWebsiteAndOrganization(): void {
		$types = $this->types(
			array(
				'home'      => true,
				'site_name' => 'Example',
				'site_url'  => 'https://example.test/',
			)
		);

		$this->assertSame( array( 'WebSite', 'Organization' ), $types );
	}

	public function testLocalBusinessIsAddedWhenTheSettingIsEnabled(): void {
		$nodes = ( new SchemaGraph() )->nodes(
			array(
				'home'      => true,
				'site_name' => 'Example',
				'site_url'  => 'https://example.test/',
				'local'     => array(
					'enabled'   => true,
					'name'      => 'Example Shop',
					'street'    => '1 High Street',
					'city'      => 'York',
					'telephone' => '01234',
				),
			)
		);

		$local = $this->find( $nodes, 'LocalBusiness' );
		$this->assertSame( 'Example Shop', $local['name'] );
		$this->assertSame( '1 High Street', $local['address']['streetAddress'] );
	}

	public function testAPostPrintsArticleAndBreadcrumbs(): void {
		$types = $this->types( $this->post() );
		$this->assertSame( array( 'Article', 'BreadcrumbList' ), $types );
	}

	public function testANewsFlagReplacesArticle(): void {
		$input            = $this->post();
		$input['is_news'] = true;
		$types            = $this->types( $input );
		$this->assertContains( 'NewsArticle', $types );
		$this->assertNotContains( 'Article', $types );
	}

	public function testAVideoUrlAddsVideoObject(): void {
		$input              = $this->post();
		$input['video_url'] = 'https://cdn.example.test/clip.mp4';
		$nodes              = ( new SchemaGraph() )->nodes( $input );
		$video              = $this->find( $nodes, 'VideoObject' );
		$this->assertSame( 'https://cdn.example.test/clip.mp4', $video['contentUrl'] );
	}

	public function testEncodeEscapesAngleBrackets(): void {
		$json = ( new SchemaGraph() )->encode(
			( new SchemaGraph() )->nodes(
				array(
					'home'      => true,
					'site_name' => 'A <b> name',
					'site_url'  => 'https://example.test/',
				)
			)
		);

		$this->assertStringNotContainsString( '<b>', $json );
		$this->assertStringContainsString( '\u003Cb\u003E', $json );
	}

	/**
	 * @param array<string, mixed> $input
	 * @return string[]
	 */
	private function types( array $input ): array {
		$types = array();
		foreach ( ( new SchemaGraph() )->nodes( $input ) as $node ) {
			$types[] = (string) $node['@type'];
		}
		return $types;
	}

	/**
	 * @param array<int, array<string, mixed>> $nodes
	 * @return array<string, mixed>
	 */
	private function find( array $nodes, string $type ): array {
		foreach ( $nodes as $node ) {
			if ( $type === ( $node['@type'] ?? '' ) ) {
				return $node;
			}
		}
		$this->fail( 'Missing ' . $type );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function post(): array {
		return array(
			'home'        => false,
			'kind'        => 'post',
			'site_name'   => 'Example',
			'site_url'    => 'https://example.test/',
			'title'       => 'Rank tracker',
			'description' => 'A page.',
			'url'         => 'https://example.test/rank-tracker/',
		);
	}
}
