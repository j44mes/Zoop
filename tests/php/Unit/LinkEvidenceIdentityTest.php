<?php
/**
 * Link evidence stays out of the scoring identity.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SEOStudio\Content\PageModel;

/**
 * A link-source hash does not change the on-page input hash.
 */
final class LinkEvidenceIdentityTest extends TestCase {
	public function testLinkSourceHashRoundTripsWithoutChangingTheScoreIdentity(): void {
		$plain   = new PageModel(
			url: 'https://example.test/about',
			renderMode: 'live',
			links: array(
				array(
					'href'     => '/pricing',
					'anchor'   => 'Pricing',
					'internal' => true,
					'nofollow' => false,
					'kind'     => 'contextual',
				),
			)
		);
		$stamped = new PageModel(
			url: 'https://example.test/about',
			renderMode: 'live',
			links: $plain->links,
			linkSourceHash: 'abc123'
		);

		$this->assertSame( $plain->inputHash(), $stamped->inputHash() );
		$this->assertSame( 'abc123', $stamped->toArray()['link_source_hash'] );
		$this->assertSame( 'abc123', PageModel::fromArray( $stamped->toArray() )->linkSourceHash );
		$this->assertArrayNotHasKey( 'link_source_hash', $this->hashedPayload( $stamped ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function hashedPayload( PageModel $model ): array {
		$data = $model->toArray();
		unset( $data['rendered_at'], $data['link_source_hash'] );
		return $data;
	}
}
