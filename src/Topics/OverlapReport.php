<?php
/**
 * Site-wide cannibalisation report.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Topics;

use SEOStudio\Architecture\SiteSurvey;

/**
 * Builds the overlap comparison from published pages and saved topic corrections.
 */
final class OverlapReport {
	public function __construct( private readonly Overlap $overlap = new Overlap() ) {}

	/**
	 * Compare every published page in the current inventory.
	 *
	 * @return array{groups: list<array<string, mixed>>, notes: string[]}
	 */
	public function report(): array {
		$evaluated = ( new SiteSurvey() )->evaluated();
		$suggester = new Suggester();
		$pages     = array();
		foreach ( $evaluated['pages'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$post_id = (int) ( $row['post_id'] ?? 0 );
			if ( $post_id <= 0 || ! get_post( $post_id ) ) {
				continue;
			}
			$suggestion = $suggester->suggest(
				array(
					'title'          => (string) ( $row['title'] ?? '' ),
					'h1'             => '',
					'keyphrase'      => (string) get_post_meta( $post_id, '_seostudio_focus_keyphrase', true ),
					'in_inventory'   => true,
					'contextual_out' => (int) ( $row['contextual_out'] ?? 0 ),
					'depth'          => $row['depth'] ?? null,
				)
			);
			$pages[]    = array(
				'post_id'   => $post_id,
				'title'     => (string) ( $row['title'] ?? '' ),
				'keyphrase' => (string) get_post_meta( $post_id, '_seostudio_focus_keyphrase', true ),
				'topic'     => $this->chosen( $post_id, TopicPage::PRIMARY, (string) $suggestion['primary_topic']['value'] ),
				'intent'    => $this->chosen( $post_id, TopicPage::INTENT, (string) $suggestion['search_intent']['value'] ),
			);
		}

		return $this->overlap->assess( $pages );
	}

	private function chosen( int $postId, string $key, string $suggestion ): string {
		$saved = get_post_meta( $postId, $key, true );
		if ( is_string( $saved ) && '' !== trim( $saved ) ) {
			return trim( $saved );
		}
		return $suggestion;
	}
}
