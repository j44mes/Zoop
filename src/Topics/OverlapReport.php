<?php
/**
 * Site-wide cannibalisation report.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Topics;

use SEOStudio\Architecture\SiteSurvey;
use SEOStudio\Storage\PagesRepository;

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
		$stored    = ( new PagesRepository() )->storedLinks();
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
			$keyphrase  = (string) get_post_meta( $post_id, '_seostudio_focus_keyphrase', true );
			$heading    = (string) ( $stored[ $post_id ]['h1'] ?? '' );
			$suggestion = $suggester->suggest(
				array(
					'title'          => (string) ( $row['title'] ?? '' ),
					'h1'             => $heading,
					'keyphrase'      => $keyphrase,
					'in_inventory'   => true,
					'contextual_out' => (int) ( $row['contextual_out'] ?? 0 ),
					'depth'          => $row['depth'] ?? null,
				)
			);
			$topic      = $this->chosen( $post_id, TopicPage::PRIMARY, $suggestion['primary_topic'] );
			$intent     = $this->chosen( $post_id, TopicPage::INTENT, $suggestion['search_intent'] );
			$pages[]    = array(
				'post_id'       => $post_id,
				'title'         => (string) ( $row['title'] ?? '' ),
				'heading'       => $heading,
				'keyphrase'     => $keyphrase,
				'topic'         => $topic['value'],
				'topic_source'  => $topic['source'],
				'intent'        => $intent['value'],
				'intent_source' => $intent['source'],
			);
		}

		return $this->overlap->assess( $pages );
	}

	/**
	 * Prefer a saved value, and keep the origin of a suggestion.
	 *
	 * @param array{value?: mixed, source?: mixed} $suggestion Suggested field.
	 * @return array{value: string, source: string}
	 */
	private function chosen( int $postId, string $key, array $suggestion ): array {
		$saved = get_post_meta( $postId, $key, true );
		if ( is_string( $saved ) && '' !== trim( $saved ) ) {
			return array(
				'value'  => trim( $saved ),
				'source' => 'saved',
			);
		}

		return array(
			'value'  => trim( (string) ( $suggestion['value'] ?? '' ) ),
			'source' => (string) ( $suggestion['source'] ?? 'none' ),
		);
	}
}
