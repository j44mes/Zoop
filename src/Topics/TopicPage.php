<?php
/**
 * Topic suggestion and the user's corrections for one post.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Topics;

use SEOStudio\Architecture\SiteSurvey;
use SEOStudio\Storage\PagesRepository;

/**
 * Reads saved SEO fields and the link graph, then keeps the user's replacement when one is stored.
 */
final class TopicPage {
	public const PRIMARY = '_seostudio_primary_topic';

	public const ORIGIN = '_seostudio_primary_topic_origin';

	public const ROLE = '_seostudio_page_role';

	public const INTENT = '_seostudio_search_intent';

	public const PARENT = '_seostudio_parent_topic';

	public const ENTITIES = '_seostudio_supporting_entities';

	/**
	 * Link graph for this request.
	 *
	 * @var array{report: array<string, mixed>, pages: array<string, array<string, mixed>>}|null
	 */
	private ?array $evaluated = null;

	public function __construct(
		private readonly Suggester $suggester = new Suggester(),
		private readonly PagesRepository $pages = new PagesRepository()
	) {}

	/**
	 * Suggestion, saved correction, and the value Zoop will use.
	 *
	 * @return array<string, mixed>
	 */
	public function present( int $postId ): array {
		$suggestions = $this->suggestFor( $postId, true );
		$saved       = $this->saved( $postId );

		return array(
			'post_id'     => $postId,
			'suggestions' => $suggestions,
			'saved'       => $saved,
			'effective'   => $this->effective( $suggestions, $saved ),
			'note'        => __( 'These suggestions come from the saved wording and the link graph. Zoop does not use a language model, and saving a field replaces only that suggestion.', 'seo-studio' ),
		);
	}

	/**
	 * Store a correction. An empty value removes the correction and the suggestion is used again.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	public function update( int $postId, array $input ): array {
		$map = array(
			'primary_topic'       => self::PRIMARY,
			'page_role'           => self::ROLE,
			'search_intent'       => self::INTENT,
			'parent_topic'        => self::PARENT,
			'supporting_entities' => self::ENTITIES,
		);
		foreach ( $map as $field => $meta_key ) {
			if ( ! array_key_exists( $field, $input ) ) {
				continue;
			}
			if ( 'supporting_entities' === $field ) {
				$entities = array();
				foreach ( (array) $input[ $field ] as $phrase ) {
					$text = sanitize_text_field( (string) $phrase );
					if ( '' !== $text ) {
						$entities[] = $text;
					}
				}
				if ( array() === $entities ) {
					delete_post_meta( $postId, $meta_key );
				} else {
					update_post_meta( $postId, $meta_key, $entities );
				}
				continue;
			}
			$value = sanitize_text_field( (string) $input[ $field ] );
			if ( '' === $value ) {
				delete_post_meta( $postId, $meta_key );
			} else {
				update_post_meta( $postId, $meta_key, $value );
			}
			if ( 'primary_topic' === $field ) {
				$this->rememberOrigin( $postId, $value );
			}
		}

		return $this->present( $postId );
	}

	/**
	 * Remember where a saved topic's words came from. Clearing the topic clears the origin.
	 */
	private function rememberOrigin( int $postId, string $value ): void {
		if ( '' === $value ) {
			delete_post_meta( $postId, self::ORIGIN );
			return;
		}
		$model  = $this->pages->loadModel( $postId );
		$title  = get_the_title( $postId );
		$origin = Overlap::topicOrigin(
			$value,
			(string) get_post_meta( $postId, '_seostudio_focus_keyphrase', true ),
			null === $model ? '' : $model->firstH1(),
			is_string( $title ) ? $title : ''
		);
		if ( 'none' === $origin ) {
			delete_post_meta( $postId, self::ORIGIN );
			return;
		}
		update_post_meta( $postId, self::ORIGIN, $origin );
	}

	/**
	 * Build the suggester input for one post.
	 *
	 * @return array<string, array{value: mixed, reason: string}>
	 */
	private function suggestFor( int $postId, bool $withParent ): array {
		$model    = $this->pages->loadModel( $postId );
		$graph    = $this->graphPage( $postId );
		$related  = $this->phrases( get_post_meta( $postId, '_seostudio_related_keyphrases', true ), true );
		$synonyms = $this->phrases( get_post_meta( $postId, '_seostudio_synonyms', true ), false );
		$parent   = '';
		if ( $withParent ) {
			$parent = $this->parentTopic( $graph['referrer_ids'] );
		}
		$title = get_the_title( $postId );

		return $this->suggester->suggest(
			array(
				'title'          => is_string( $title ) ? $title : '',
				'h1'             => null === $model ? '' : $model->firstH1(),
				'keyphrase'      => (string) get_post_meta( $postId, '_seostudio_focus_keyphrase', true ),
				'related'        => $related,
				'synonyms'       => $synonyms,
				'in_inventory'   => $graph['in_inventory'],
				'contextual_out' => $graph['contextual_out'],
				'depth'          => $graph['depth'],
				'parent_topic'   => $parent,
			)
		);
	}

	/**
	 * Topic of the closest contextual referring page.
	 *
	 * @param string[] $referrerIds
	 */
	private function parentTopic( array $referrerIds ): string {
		$pages      = $this->evaluated()['pages'];
		$best_id    = 0;
		$best_depth = PHP_INT_MAX;
		foreach ( $referrerIds as $referrer ) {
			if ( ! isset( $pages[ $referrer ] ) ) {
				continue;
			}
			$row   = $pages[ $referrer ];
			$depth = is_int( $row['depth'] ?? null ) ? (int) $row['depth'] : PHP_INT_MAX;
			$post  = (int) ( $row['post_id'] ?? 0 );
			if ( $post <= 0 ) {
				continue;
			}
			if ( $depth < $best_depth || ( $depth === $best_depth && ( 0 === $best_id || $post < $best_id ) ) ) {
				$best_depth = $depth;
				$best_id    = $post;
			}
		}
		if ( $best_id <= 0 ) {
			return '';
		}
		$saved = get_post_meta( $best_id, self::PRIMARY, true );
		if ( is_string( $saved ) && '' !== trim( $saved ) ) {
			return trim( $saved );
		}
		$suggested = $this->suggestFor( $best_id, false );
		return trim( (string) ( $suggested['primary_topic']['value'] ?? '' ) );
	}

	/**
	 * Load the link graph once per request.
	 *
	 * @return array{report: array<string, mixed>, pages: array<string, array<string, mixed>>}
	 */
	private function evaluated(): array {
		if ( null === $this->evaluated ) {
			$this->evaluated = ( new SiteSurvey() )->evaluated();
		}
		return $this->evaluated;
	}

	/**
	 * Link-graph fields for one inventory page.
	 *
	 * @return array{in_inventory: bool, contextual_out: int, depth: int|null, referrer_ids: string[]}
	 */
	private function graphPage( int $postId ): array {
		$pages = $this->evaluated()['pages'];
		$row   = $pages[ 'post:' . $postId ] ?? null;
		if ( ! is_array( $row ) ) {
			return array(
				'in_inventory'   => false,
				'contextual_out' => 0,
				'depth'          => null,
				'referrer_ids'   => array(),
			);
		}

		return array(
			'in_inventory'   => true,
			'contextual_out' => (int) ( $row['contextual_out'] ?? 0 ),
			'depth'          => is_int( $row['depth'] ?? null ) ? $row['depth'] : null,
			'referrer_ids'   => array_map( 'strval', (array) ( $row['contextual_referrer_ids'] ?? array() ) ),
		);
	}

	/**
	 * Corrections stored on the post. Empty means the suggestion is still in use.
	 *
	 * @return array<string, string|string[]>
	 */
	private function saved( int $postId ): array {
		$entities = get_post_meta( $postId, self::ENTITIES, true );
		return array(
			'primary_topic'       => $this->stringMeta( $postId, self::PRIMARY ),
			'page_role'           => $this->stringMeta( $postId, self::ROLE ),
			'search_intent'       => $this->stringMeta( $postId, self::INTENT ),
			'parent_topic'        => $this->stringMeta( $postId, self::PARENT ),
			'supporting_entities' => is_array( $entities ) ? array_values( array_map( 'strval', $entities ) ) : array(),
		);
	}

	private function stringMeta( int $postId, string $key ): string {
		$value = get_post_meta( $postId, $key, true );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Use a saved correction when the field is not empty.
	 *
	 * @param array<string, array{value: mixed, reason: string}> $suggestions
	 * @param array<string, string|string[]>                     $saved
	 * @return array<string, mixed>
	 */
	private function effective( array $suggestions, array $saved ): array {
		$effective = array();
		foreach ( $suggestions as $field => $suggestion ) {
			$stored              = $saved[ $field ] ?? '';
			$empty               = is_array( $stored ) ? array() === $stored : '' === trim( (string) $stored );
			$effective[ $field ] = $empty ? $suggestion['value'] : $stored;
		}
		return $effective;
	}

	/**
	 * Flatten related phrases and synonyms into a list of strings.
	 *
	 * @return string[]
	 */
	private function phrases( mixed $stored, bool $related ): array {
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$phrases = array();
		foreach ( $stored as $entry ) {
			if ( is_string( $entry ) ) {
				$phrases[] = $entry;
				continue;
			}
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( $related && isset( $entry['keyphrase'] ) ) {
				$phrases[] = (string) $entry['keyphrase'];
			}
			foreach ( (array) ( $entry['synonyms'] ?? array() ) as $synonym ) {
				$phrases[] = (string) $synonym;
			}
		}
		return $phrases;
	}
}
