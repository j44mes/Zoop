<?php
/**
 * Definitive scores follow observed published evidence and stay fresh.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Tests\Integration;

use SEOStudio\Content\FetchPolicy;
use SEOStudio\Content\LoopbackFetcher;
use SEOStudio\Core\Plugin;
use SEOStudio\Jobs\AnalysePost;
use SEOStudio\Rest\PagesController;
use SEOStudio\Storage\PagesRepository;
use SEOStudio\Storage\PublishedFreshness;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Source-hash invalidation and the published-page gate.
 */
final class EvidenceReadinessTest extends WP_UnitTestCase {
	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		Plugin::init();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$home = static function (): string {
			return 'https://example.test';
		};
		add_filter( 'pre_option_home', $home );
		add_filter( 'pre_option_siteurl', $home );
	}

	public function test_a_title_update_withholds_the_definitive_score_until_reverified(): void {
		$post = $this->post( 'Rank tracker title' );
		$first = $this->live( $post, $this->html( 'Rank tracker title', 'Rank tracker title' ) );
		$this->assertTrue( $first['presentation']['definitive'] );
		$this->assertStringContainsString( 'Based on', $first['presentation']['explanation'] );
		$this->assertNotSame( '', $first['presentation']['verified_label'] );

		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => 'Changed title',
			)
		);
		$stale = $this->record( $post );
		$this->assertFalse( $stale['presentation']['definitive'] );
		$this->assertTrue( $stale['presentation']['historical'] );
		$this->assertSame( 'Out of date', $stale['presentation']['headline'] );
		$this->assertStringContainsString( 'Rank tracker title', (string) $stale['page_model']['title_tag'] );

		$again = $this->live( $post, $this->html( 'Changed title', 'Changed title' ) );
		$this->assertTrue( $again['presentation']['definitive'] );
		$this->assertStringContainsString( 'Changed title', (string) $again['page_model']['title_tag'] );
	}

	public function test_a_content_change_and_a_theme_change_keep_the_snapshot_historical(): void {
		$post = $this->post( 'Rank tracker title' );
		$this->live( $post, $this->html( 'Rank tracker title', 'Theme heading' ) );
		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => '<h1>Inside the article</h1><p>Changed body.</p>',
			)
		);
		$content = $this->record( $post );
		$this->assertFalse( $content['presentation']['definitive'] );
		$this->assertSame( 'Theme heading', $content['page_model']['document_h1'][0] ?? '' );

		$post = $this->post( 'Rank tracker title' );
		$this->live( $post, $this->html( 'Rank tracker title', 'Theme heading' ) );
		PublishedFreshness::siteChanged();
		$theme = $this->record( $post );
		$this->assertFalse( $theme['presentation']['definitive'] );
		$this->assertSame( 'needs_reanalysis', $theme['score_state'] ?? $this->row( $post )['onpage_state'] );
	}

	public function test_an_unchanged_content_audit_keeps_a_ready_snapshot(): void {
		$post = $this->post( 'Rank tracker title' );
		$this->live( $post, $this->html( 'Rank tracker title', 'Theme heading' ) );
		$worker = new AnalysePost();
		$this->assertTrue( $worker->handle( $post ) );
		$this->assertSame( 'published_evidence_kept', $worker->lastOutcome );
		$this->assertTrue( $this->record( $post )['presentation']['definitive'] );
	}

	public function test_a_changed_content_audit_does_not_restore_readiness(): void {
		$post = $this->post( 'Rank tracker title' );
		$this->live( $post, $this->html( 'Rank tracker title', 'Theme heading' ) );
		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => 'Changed title',
			)
		);
		$worker = new AnalysePost();
		$this->assertTrue( $worker->handle( $post ) );
		$this->assertSame( 'published_evidence_kept', $worker->lastOutcome );
		$row = $this->record( $post );
		$this->assertFalse( $row['presentation']['definitive'] );
		$this->assertStringContainsString( 'Rank tracker title', (string) $row['page_model']['title_tag'] );
	}

	public function test_switching_keyphrases_does_not_revive_invalidated_evidence(): void {
		$post = $this->post( 'Rank tracker title' );
		update_post_meta( $post, '_seostudio_focus_keyphrase', 'rank tracker' );
		$this->live( $post, $this->html( 'Rank tracker title', 'Theme heading' ) );
		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => 'Changed title',
			)
		);
		update_post_meta( $post, '_seostudio_focus_keyphrase', 'other phrase' );
		update_post_meta( $post, '_seostudio_focus_keyphrase', 'rank tracker' );
		$this->assertFalse( $this->record( $post )['presentation']['definitive'] );
	}

	public function test_content_analysis_does_not_claim_full_document_observation(): void {
		$post = $this->post( 'Rank tracker title' );
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'object_type', 'post' );
		$request->set_param( 'object_id', $post );
		$request->set_param( 'mode', 'content' );
		$request->set_param( 'keyphrase', 'rank tracker' );
		$result = ( new PagesController() )->analyseObject( $request );
		$data   = $result->get_data();
		$this->assertFalse( $data['presentation']['definitive'] );
		$this->assertSame( 'not_observed', $data['page_model']['document_h1_evidence'] );
		$this->assertNotSame( 'indexable', $data['presentation']['search_status'] );
		$this->assertNotSame( 'noindex', $data['presentation']['search_status'] );
	}

	public function test_a_fetched_page_without_a_title_or_heading_scores_the_absence(): void {
		$post = $this->post( 'Rank tracker title' );
		$data = $this->live(
			$post,
			'<html><head><meta name="robots" content="index, max-image-preview:none" /></head><body><main><p>Rank tracker body copy for the page.</p></main></body></html>'
		);
		$this->assertSame( 'indexable', $data['presentation']['search_status'] );
		$this->assertNotSame( 'Hidden from search', $data['presentation']['search_label'] );
		$this->assertTrue( $data['presentation']['definitive'] );
		$this->assertSame( array(), $data['presentation']['missing'] );
		$checks = array();
		foreach ( $data['presentation']['checks'] as $check ) {
			$checks[ $check['id'] ] = $check;
		}
		$this->assertSame( 'needs_work', $checks['title.exists']['state'] );
		$this->assertSame( 'needs_work', $checks['title.entire_keyphrase']['state'] );
		$this->assertNotSame( 'not_applicable', $checks['title.entire_keyphrase']['state'] );
		$this->assertSame( 'needs_work', $checks['headings.h1_count']['state'] );
		$this->assertSame( 'title', $checks['title.exists']['category'] );
		$this->assertArrayHasKey( 'measured', $checks['title.exists'] );
		$this->assertNotSame( '', $checks['title.exists']['recommendation'] );
		$this->assertGreaterThan( 0, $data['scores'][0]['denominator'] );
		$this->assertLessThan( $data['scores'][0]['denominator'], $data['scores'][0]['score'] );
	}

	public function test_explicit_noindex_does_not_change_the_content_points(): void {
		$post = $this->post( 'Rank tracker title' );
		$open = $this->live( $post, $this->html( 'Rank tracker title', 'Theme heading' ) );
		$hidden = $this->live( $post, $this->html( 'Rank tracker title', 'Theme heading', 'noindex' ) );
		$this->assertSame( 'noindex', $hidden['presentation']['search_status'] );
		$this->assertSame( 'Hidden from search', $hidden['presentation']['search_label'] );
		$this->assertSame( $open['scores'][0]['score'], $hidden['scores'][0]['score'] );
		$this->assertSame( $open['scores'][0]['rating'], $hidden['scores'][0]['rating'] );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function live( int $postId, string $html ): array {
		$fetcher = new LoopbackFetcher(
			policy: new FetchPolicy( static fn (): array => array( '93.184.216.34' ) ),
			transport: static fn (): array => array(
				'code'     => 200,
				'location' => '',
				'body'     => $html,
				'robots'   => '',
			)
		);
		add_filter(
			'post_link',
			static function () use ( $postId ): string {
				return 'https://example.test/?p=' . $postId;
			}
		);
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'object_type', 'post' );
		$request->set_param( 'object_id', $postId );
		$request->set_param( 'mode', 'live' );
		$request->set_param( 'keyphrase', 'rank tracker' );
		$result = ( new PagesController( fetcher: $fetcher ) )->analyseObject( $request );
		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$data = $result->get_data();
		$this->assertIsArray( $data );

		return $data;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function record( int $postId ): array {
		$row = ( new PagesRepository() )->findByObject( 'post', $postId );
		$this->assertIsArray( $row );
		$request = new WP_REST_Request( 'GET' );
		$request->set_param( 'page_id', (int) $row['id'] );
		$result = ( new \SEOStudio\Rest\PagesController() )->show( $request );
		$this->assertNotInstanceOf( \WP_Error::class, $result );

		return $result->get_data();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function row( int $postId ): array {
		$row = ( new PagesRepository() )->findByObject( 'post', $postId );
		$this->assertIsArray( $row );

		return $row;
	}

	private function post( string $title ): int {
		$post = self::factory()->post->create(
			array(
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_content' => '<h1>Inside the article</h1><p>Body copy for the page.</p>',
			)
		);
		update_post_meta( $post, '_seostudio_focus_keyphrase', 'rank tracker' );

		return $post;
	}

	private function html( string $title, string $heading, string $robots = '' ): string {
		$meta = '' === $robots ? '' : '<meta name="robots" content="' . esc_attr( $robots ) . '" />';

		return '<html><head><title>' . esc_html( $title ) . '</title>' . $meta . '</head><body><header><h1>' . esc_html( $heading ) . '</h1></header><main><p>Body copy for the page.</p></main></body></html>';
	}
}
