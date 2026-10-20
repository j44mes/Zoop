<?php
/**
 * Live evidence from a rendered theme, not from a REST head buffer.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Content\FetchPolicy;
use SEOStudio\Content\LoopbackFetcher;
use SEOStudio\Content\PageModel;
use SEOStudio\Content\Renderer;
use SEOStudio\Core\Plugin;
use SEOStudio\Core\Settings;
use SEOStudio\Integrations\Detector;
use SEOStudio\Scoring\OnPage\Score;
use SEOStudio\Scoring\OnPage\Scorer;
use WP_UnitTestCase;

/**
 * L1, L3, L4, L5, the static homepage, and Yoast coexistence.
 */
final class LiveHtmlEvidenceTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		Plugin::init();
	}

	public function tear_down(): void {
		Detector::clearForce();
		parent::tear_down();
	}

	public function test_rendered_html_verifies_title_h1s_and_a_missing_description(): void {
		$post = $this->post( 'how to price a cleaning contract', '<h1>Inside the article</h1><p>Body copy for the page.</p>' );
		$html = $this->render( (string) get_permalink( $post ) );
		$this->assertStringContainsString( 'how to price a cleaning contract', $html );
		$this->assertStringContainsString( '<h1>how to price a cleaning contract</h1>', $html );
		$this->assertStringContainsString( '<h1>Inside the article</h1>', $html );
		$this->assertStringNotContainsString( 'name="description"', $html );

		$model = $this->observe( $html );
		$score = ( new Scorer() )->score( $model, 'how to price a cleaning contract' );
		$this->assertSame( 'observed', $model->titleEvidence );
		$this->assertStringContainsString( 'how to price a cleaning contract', $model->titleTag );
		$this->assertSame( array( 'how to price a cleaning contract', 'Inside the article' ), $model->documentH1 );
		$this->assertSame( array( 'Inside the article' ), $model->h1 );
		$this->assertSame( 'pass', $this->status( $score, 'title.entire_keyphrase' ) );
		$this->assertSame( 6, $this->earned( $score, 'title.entire_keyphrase' ) );
		$this->assertNotSame( 'not_checked', $this->status( $score, 'title.entire_keyphrase' ) );
		$this->assertSame( 'pass', $this->status( $score, 'headings.entire_keyphrase' ) );
		$this->assertSame( 3, $this->earned( $score, 'headings.entire_keyphrase' ) );
		$this->assertSame( 'fail', $this->status( $score, 'headings.h1_count' ) );
		$this->assertSame( 0, $this->earned( $score, 'headings.h1_count' ) );
		$this->assertSame( 'observed_absent', $model->descriptionEvidence );
		$this->assertSame( 'not_applicable', $this->status( $score, 'meta.keyphrase' ) );
		$this->assertSame( 0, $this->earned( $score, 'meta.keyphrase' ) );
		$this->assertSame( '', $this->recommendation( $score, 'meta.keyphrase' ) );
		$this->assertSame( $this->applicable( $score ), $score->denominator );
	}

	public function test_an_automatic_excerpt_matches_the_observed_description(): void {
		$this->mode( 'owner' );
		$excerpt = 'Published excerpt for the rank tracker page.';
		$post    = $this->post( 'Rank tracker', '<p>Body copy.</p>', $excerpt );
		$html    = $this->render( (string) get_permalink( $post ) );
		$this->assertStringContainsString( 'content="' . $excerpt . '"', $html );

		$model = $this->observe( $html );
		$this->assertSame( 'observed', $model->descriptionEvidence );
		$this->assertSame( $excerpt, $model->metaDescription );
		$this->assertNotSame( 'not_checked', ( new Scorer() )->score( $model, 'rank tracker' )->assessments[0]->status );
	}

	public function test_a_blocked_fetch_does_not_invent_observed_evidence(): void {
		$this->mode( 'owner' );
		$post = $this->post( 'Rank tracker', '<p>Body copy.</p>' );
		update_post_meta( $post, '_seostudio_title', 'how to price a cleaning contract' );
		update_post_meta( $post, '_seostudio_focus_keyphrase', 'how to price a cleaning contract' );
		$calculated = ( new Renderer() )->renderPost( $post );
		$this->assertSame( 'calculated', $calculated->titleEvidence );

		$fetcher = new LoopbackFetcher(
			policy: new FetchPolicy( static fn (): array => array( '93.184.216.34' ) ),
			transport: static function (): array {
				throw new \RuntimeException( 'The blocked transport was called.' );
			}
		);
		$filter = static fn (): string => 'http://localhost/secret';
		add_filter( 'post_link', $filter );
		$request = new \WP_REST_Request( 'POST' );
		$request->set_param( 'object_type', 'post' );
		$request->set_param( 'object_id', $post );
		$request->set_param( 'mode', 'live' );
		$request->set_param( 'keyphrase', 'how to price a cleaning contract' );
		$result = ( new \SEOStudio\Rest\PagesController( fetcher: $fetcher ) )->analyseObject( $request );
		remove_filter( 'post_link', $filter );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'seostudio_fetch_blocked', $result->get_error_code() );
		$again = ( new Renderer() )->renderPost( $post );
		$this->assertSame( 'calculated', $again->titleEvidence );
		$this->assertNotSame( 'observed', $again->titleEvidence );
		$this->assertNotSame( 'observed_absent', $again->descriptionEvidence );
	}

	public function test_live_html_replaces_a_provisional_title(): void {
		$this->mode( 'owner' );
		$post = $this->post( 'Post title', '<h1>Inside the article</h1><p>Body copy.</p>' );
		update_post_meta( $post, '_seostudio_title', 'how to price a cleaning contract' );
		$provisional = ( new Renderer() )->renderPost( $post );
		$this->assertSame( 'calculated', $provisional->titleEvidence );
		$this->assertStringContainsString( 'not fetched HTML', $this->explanation( ( new Scorer() )->score( $provisional, 'how to price a cleaning contract' ), 'title.entire_keyphrase' ) );

		$html = $this->render( (string) get_permalink( $post ) );
		$model = $this->observe( $html );
		$score = ( new Scorer() )->score( $model, 'how to price a cleaning contract' );
		$this->assertSame( 'observed', $model->titleEvidence );
		$this->assertSame( 'pass', $this->status( $score, 'title.entire_keyphrase' ) );
		$this->assertStringNotContainsString( 'not fetched HTML', $this->explanation( $score, 'title.entire_keyphrase' ) );
	}

	public function test_static_homepage_metadata_is_observed_from_html(): void {
		$this->mode( 'owner' );
		$page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Inside page',
				'post_content' => '<p>Home body.</p>',
			)
		);
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page );
		$settings                     = Settings::all();
		$settings['home_title']       = 'Rank tracker home';
		$settings['home_description'] = 'Home rank tracker description';
		update_option( Settings::OPTION, $settings );
		$html = $this->render( (string) home_url( '/' ) );
		$this->assertStringContainsString( 'Rank tracker home', $html );
		$this->assertStringContainsString( 'Home rank tracker description', $html );

		$model = $this->observe( $html );
		$this->assertSame( 'observed', $model->titleEvidence );
		$this->assertSame( 'observed', $model->descriptionEvidence );
		$this->assertStringContainsString( 'Rank tracker home', $model->titleTag );
		$this->assertSame( 'Home rank tracker description', $model->metaDescription );
	}

	public function test_yoast_description_is_observed_and_an_excerpt_is_not_invented(): void {
		Detector::force(
			array(
				'slug'    => 'yoast',
				'name'    => 'Yoast SEO',
				'version' => '1.0.0',
			)
		);
		$post = $this->post( 'Rank tracker', '<p>Body copy.</p>', 'Secret excerpt' );
		$print = static function (): void {
			echo '<meta name="description" content="Yoast rank tracker description" />' . "\n";
		};
		add_action( 'wp_head', $print );
		$html = $this->render( (string) get_permalink( $post ) );
		remove_action( 'wp_head', $print );
		$this->assertStringContainsString( 'Yoast rank tracker description', $html );
		$model = $this->observe( $html );
		$this->assertSame( 'observed', $model->descriptionEvidence );
		$this->assertSame( 'Yoast rank tracker description', $model->metaDescription );
		$this->assertNotSame( 'Secret excerpt', $model->metaDescription );

		$html = $this->render( (string) get_permalink( $post ) );
		$this->assertStringNotContainsString( 'Secret excerpt', $html );
		$absent = $this->observe( $html );
		$this->assertSame( 'observed_absent', $absent->descriptionEvidence );
		$this->assertSame( '', $absent->metaDescription );
		$this->assertSame( 'not_applicable', $this->status( ( new Scorer() )->score( $absent, 'rank tracker' ), 'meta.keyphrase' ) );
	}

	private function post( string $title, string $content, string $excerpt = '' ): int {
		return self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => $excerpt,
			)
		);
	}

	private function render( string $url ): string {
		$this->go_to( $url );
		ob_start();
		include dirname( __DIR__, 2 ) . '/fixtures/themes/observe/index.php';
		return (string) ob_get_clean();
	}

	private function observe( string $html ): PageModel {
		// wp-env sets the site home to localhost. The policy blocks that host, and the
		// injected transport is reached only after the allow check. Point this fetch at
		// a public origin without changing the rendered HTML or the policy.
		$home = static function (): string {
			return 'https://example.test';
		};
		add_filter( 'pre_option_home', $home );
		add_filter( 'pre_option_siteurl', $home );
		try {
			$fetcher = new LoopbackFetcher(
				policy: new FetchPolicy( static fn (): array => array( '93.184.216.34' ) ),
				transport: static fn (): array => array(
					'code'     => 200,
					'location' => '',
					'body'     => $html,
					'robots'   => '',
				)
			);
			return $fetcher->fetch( 'https://example.test/observed-page/' );
		} finally {
			remove_filter( 'pre_option_home', $home );
			remove_filter( 'pre_option_siteurl', $home );
		}
	}

	private function mode( string $mode ): void {
		$settings         = Settings::all();
		$settings['mode'] = $mode;
		update_option( Settings::OPTION, $settings );
	}

	private function status( Score $score, string $id ): string {
		return $this->result( $score, $id )->status;
	}

	private function earned( Score $score, string $id ): int {
		return $this->result( $score, $id )->earned;
	}

	private function recommendation( Score $score, string $id ): string {
		return $this->result( $score, $id )->recommendation;
	}

	private function explanation( Score $score, string $id ): string {
		return $this->result( $score, $id )->explanation;
	}

	private function applicable( Score $score ): int {
		$max = 0;
		foreach ( $score->assessments as $result ) {
			if ( 'not_checked' === $result->status || 'not_applicable' === $result->status ) {
				continue;
			}
			$max += $result->max;
		}
		return $max;
	}

	private function result( Score $score, string $id ): \SEOStudio\Scoring\OnPage\AssessmentResult {
		foreach ( $score->assessments as $result ) {
			if ( $id === $result->id ) {
				return $result;
			}
		}
		$this->fail( 'Missing ' . $id );
	}
}
