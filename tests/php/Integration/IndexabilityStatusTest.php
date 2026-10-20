<?php
/**
 * Indexability statuses stay distinct from a noindex directive.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Content\PageModel;
use SEOStudio\Core\Plugin;
use SEOStudio\Scoring\OnPage\ScorePresentation;
use SEOStudio\Storage\Indexability;
use WP_UnitTestCase;

/**
 * Password, privacy, site visibility, and robots directives.
 */
final class IndexabilityStatusTest extends WP_UnitTestCase {
	private int $public = 0;

	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		update_option( 'blog_public', '1' );
		$this->public = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Public page',
			)
		);
	}

	public function tear_down(): void {
		update_option( 'blog_public', '1' );
		parent::tear_down();
	}

	public function test_directive_statuses_and_visibility_stay_separate(): void {
		$live = $this->model( 'index, max-image-preview:none', '' );
		$this->assertSame( 'indexable', Indexability::describe( $this->public, $live )['status'] );
		$this->assertTrue( Indexability::forPost( $this->public, $live ) );
		$this->assertSame( 'Indexable', ScorePresentation::searchLabel( 'indexable' ) );

		$this->assertSame( 'noindex', Indexability::describe( $this->public, $this->model( 'noindex', '' ) )['status'] );
		$this->assertSame( 'noindex', Indexability::describe( $this->public, $this->model( 'none', '' ) )['status'] );
		$this->assertSame( 'noindex', Indexability::describe( $this->public, $this->model( 'noindex,nofollow', '' ) )['status'] );
		$this->assertSame( 'noindex', Indexability::describe( $this->public, $this->model( 'googlebot: noindex', '' ) )['status'] );
		$conflict = Indexability::describe( $this->public, $this->model( 'index', 'noindex' ) );
		$this->assertSame( 'noindex', $conflict['status'] );
		$this->assertSame( 'http', $conflict['source'] );
		$this->assertFalse( Indexability::forPost( $this->public, $this->model( 'noindex, follow', '' ) ) );

		$password = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
				'post_title'    => 'Password page',
			)
		);
		$private  = self::factory()->post->create(
			array(
				'post_status' => 'private',
				'post_title'  => 'Private page',
			)
		);
		$this->assertSame( 'not_public', Indexability::describe( $password, $this->model( 'noindex', '' ) )['status'] );
		$this->assertSame( 'not_public', Indexability::describe( $private, $live )['status'] );
		$this->assertNotSame( 'Hidden from search', ScorePresentation::searchLabel( 'not_public' ) );

		update_option( 'blog_public', '0' );
		$discouraged = Indexability::describe( $this->public, $live );
		$this->assertSame( 'site_discouraged', $discouraged['status'] );
		$this->assertSame( 'blog_public', $discouraged['source'] );
		$this->assertNotSame( 'Hidden from search', ScorePresentation::searchLabel( 'site_discouraged' ) );
		$this->assertNotSame( 'noindex', $discouraged['status'] );
		update_option( 'blog_public', '1' );

		$unknown = Indexability::describe( $this->public, new PageModel( renderMode: 'live', metadataStatus: 'estimated' ) );
		$this->assertSame( 'unobserved', $unknown['status'] );
		$this->assertSame( 'Indexability has not been checked', ScorePresentation::searchLabel( 'unobserved' ) );
	}

	private function model( string $meta, string $http ): PageModel {
		return new PageModel(
			renderMode: 'live',
			metadataStatus: 'observed_html',
			robots: trim( $meta . ', ' . $http, ', ' ),
			robotsMeta: $meta,
			robotsHttp: $http
		);
	}
}
