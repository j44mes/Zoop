<?php
/**
 * Trusted aliases resolve to the primary inventory page.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Architecture\Graph;
use SEOStudio\Architecture\UrlKey;
use SEOStudio\Content\Extractor;
use SEOStudio\Core\Settings;

/**
 * www links stay out of the graph until the hostname is trusted, then match the real page.
 */
final class AliasIdentityTest extends TestCase {
	public function testAnUntrustedTwinIsNotAnIncomingLinkAndWithholdsTheOrphan(): void {
		$result = $this->graph( array() );
		$about  = $result['pages']['post:2'];
		$this->assertSame( 0, $about['contextual_referrers'] );
		$this->assertSame( 'unknown', $about['status'] );
		$this->assertStringContainsString( 'www.example.test', (string) $about['evidence'] );
		$this->assertNotSame( 'orphan', $about['status'] );
		$notice = $result['report']['possible_aliases'][0];
		$this->assertSame( 'www.example.test', $notice['host'] );
		$this->assertSame( 1, $notice['count'] );
		$this->assertStringContainsString( 'may be this site', $notice['message'] );
	}

	public function testATrustedTwinResolvesToThePrimaryInventoryPage(): void {
		$result = $this->graph( array( 'www.example.test' ) );
		$about  = $result['pages']['post:2'];
		$this->assertSame( 1, $about['contextual_referrers'] );
		$this->assertSame( array( 'post:3' ), $about['contextual_referrer_ids'] );
		$this->assertSame( 'weak', $about['status'] );
		$this->assertSame( array(), $result['report']['possible_aliases'] );
	}

	public function testPrimaryIdentityKeepsThePathAndDropsOnlyTheAliasHost(): void {
		$identity = UrlKey::identity( 'https://www.example.test/about?p=4' );
		$this->assertSame(
			'example.test/about?p=4',
			UrlKey::primaryIdentity( $identity, 'https://example.test/', array( 'www.example.test' ) )
		);
		$this->assertSame( '', UrlKey::primaryIdentity( $identity, 'https://example.test/' ) );
		$this->assertSame( 'www.example.test', UrlKey::wwwTwinHost( $identity, 'https://example.test/' ) );
		$this->assertSame( '', UrlKey::wwwTwinHost( UrlKey::identity( 'https://blog.example.test/about' ), 'https://example.test/' ) );
		$this->assertSame( '', UrlKey::wwwTwinHost( UrlKey::identity( 'https://www.example.test/shop' ), 'https://example.test/blog/' ) );
		$this->assertSame(
			'www.example.test:8443',
			UrlKey::wwwTwinHost( UrlKey::identity( 'https://www.example.test:8443/about' ), 'https://example.test:8443/' )
		);
		$this->assertSame( '', UrlKey::wwwTwinHost( UrlKey::identity( 'https://www.example.test/about' ), 'https://example.test:8443/' ) );
	}

	public function testHostAliasValidationRejectsPathsAndBadPorts(): void {
		$this->assertTrue( Settings::isHostAlias( 'www.example.test' ) );
		$this->assertTrue( Settings::isHostAlias( 'www.example.test:8443' ) );
		$this->assertFalse( Settings::isHostAlias( 'https://www.example.test/path' ) );
		$this->assertFalse( Settings::isHostAlias( 'www.example.test:0' ) );
		$this->assertFalse( Settings::isHostAlias( 'blog.example.test/about' ) );
	}

	public function testExtractedInternalFlagUsesTheBoundaryRatherThanALiteralHost(): void {
		$html = '<html><body><main>'
			. '<a href="https://www.example.test/about">Alias</a>'
			. '<a href="https://example.test:8443/about">Port</a>'
			. '<a href="/about">Relative</a>'
			. '</main></body></html>';
		$model = ( new Extractor() )->extract( $html, array( 'url' => 'https://example.test/source' ) );
		$this->assertFalse( $model->links[0]['internal'] );
		$this->assertFalse( $model->links[1]['internal'] );
		$this->assertTrue( $model->links[2]['internal'] );
	}

	/**
	 * @param string[] $aliases
	 * @return array{report: array<string, mixed>, pages: array<string, array<string, mixed>>}
	 */
	private function graph( array $aliases ): array {
		return ( new Graph() )->evaluate(
			array(
				array(
					'id'       => 'post:1',
					'post_id'  => 1,
					'title'    => 'Home',
					'url'      => 'https://example.test/',
					'analysed' => true,
					'links'    => array(),
				),
				array(
					'id'       => 'post:2',
					'post_id'  => 2,
					'title'    => 'About',
					'url'      => 'https://example.test/about',
					'analysed' => true,
					'links'    => array(),
				),
				array(
					'id'       => 'post:3',
					'post_id'  => 3,
					'title'    => 'Source',
					'url'      => 'https://example.test/source',
					'analysed' => true,
					'links'    => array(
						array(
							'href'   => 'https://www.example.test/about',
							'anchor' => 'About',
							'kind'   => 'contextual',
						),
					),
				),
			),
			array(),
			array(
				'home'              => 'https://example.test/',
				'front_id'          => 'post:1',
				'coverage_complete' => true,
				'missing'           => 0,
				'aliases'           => $aliases,
			)
		);
	}
}
