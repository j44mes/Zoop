<?php
/**
 * Deterministic topic suggestions.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Topics;

/**
 * Suggests a topic, role, intent, parent, and supporting phrases from fields the user already has.
 */
final class Suggester {
	public const ROLES = array( 'hub', 'supporting', 'utility', 'unassigned' );

	public const INTENTS = array( 'informational', 'commercial', 'navigational', 'unassigned' );

	/**
	 * Suggest values the user can replace.
	 *
	 * @param array<string, mixed> $page
	 * @return array<string, array{value: mixed, reason: string, source?: string}>
	 */
	public function suggest( array $page ): array {
		$title     = trim( (string) ( $page['title'] ?? '' ) );
		$heading   = trim( (string) ( $page['h1'] ?? '' ) );
		$keyphrase = trim( (string) ( $page['keyphrase'] ?? '' ) );
		$primary   = $this->primary( $keyphrase, $heading, $title );
		$intent    = $this->intent( $keyphrase, $heading, $title );

		return array(
			'primary_topic'       => $primary,
			'page_role'           => $this->role( $page, (string) $intent['value'] ),
			'search_intent'       => $intent,
			'parent_topic'        => $this->parentTopic( trim( (string) ( $page['parent_topic'] ?? '' ) ) ),
			'supporting_entities' => $this->entities( $page, (string) $primary['value'] ),
		);
	}

	/**
	 * Choose the primary topic from the phrase, heading, or title.
	 *
	 * @return array{value: string, reason: string, source: string}
	 */
	private function primary( string $keyphrase, string $heading, string $title ): array {
		if ( '' !== $keyphrase ) {
			return array(
				'value'  => $keyphrase,
				'reason' => __( 'Suggested from the target phrase saved on this page.', 'seo-studio' ),
				'source' => 'keyphrase',
			);
		}
		if ( '' !== $heading ) {
			return array(
				'value'  => $heading,
				'reason' => __( 'Suggested from the first heading. No target phrase is saved.', 'seo-studio' ),
				'source' => 'heading',
			);
		}
		if ( '' !== $title ) {
			return array(
				'value'  => $title,
				'reason' => __( 'Suggested from the page title. No target phrase or heading is saved.', 'seo-studio' ),
				'source' => 'title',
			);
		}

		return array(
			'value'  => '',
			'reason' => __( 'No target phrase, heading, or title is available.', 'seo-studio' ),
			'source' => 'none',
		);
	}

	/**
	 * Match a small word list. This is not search data.
	 *
	 * @return array{value: string, reason: string, source: string}
	 */
	private function intent( string $keyphrase, string $heading, string $title ): array {
		$words = $this->words( $keyphrase . ' ' . $heading . ' ' . $title );
		$found = '';
		foreach ( array(
			'commercial'    => array( 'price', 'buy', 'service', 'services', 'install', 'installation', 'hire', 'cost', 'quote' ),
			'informational' => array( 'how', 'what', 'why', 'guide', 'tips' ),
			'navigational'  => array( 'contact', 'login', 'about', 'home' ),
		) as $intent => $needles ) {
			foreach ( $needles as $needle ) {
				if ( in_array( $needle, $words, true ) ) {
					$found = $intent;
					break 2;
				}
			}
		}
		if ( '' === $found ) {
			return array(
				'value'  => 'unassigned',
				'reason' => __( 'The title, heading, and target phrase do not use a recognised intent word. This is not Search Console data.', 'seo-studio' ),
				'source' => 'unassigned',
			);
		}

		return array(
			'value'  => $found,
			'reason' => __( 'Suggested from the wording on this page. This is not Search Console data.', 'seo-studio' ),
			'source' => 'suggested',
		);
	}

	/**
	 * Suggest a role from inventory, wording, and link depth.
	 *
	 * @param array<string, mixed> $page
	 * @return array{value: string, reason: string}
	 */
	private function role( array $page, string $intent ): array {
		if ( empty( $page['in_inventory'] ) ) {
			return array(
				'value'  => 'unassigned',
				'reason' => __( 'This page is not in the published inventory, so a role is not suggested.', 'seo-studio' ),
			);
		}
		if ( 'navigational' === $intent ) {
			return array(
				'value'  => 'utility',
				'reason' => __( 'The wording looks like a utility page.', 'seo-studio' ),
			);
		}
		$out   = (int) ( $page['contextual_out'] ?? 0 );
		$depth = $page['depth'] ?? null;
		$depth = is_int( $depth ) ? $depth : null;
		if ( $out >= 3 && null !== $depth && $depth <= 1 ) {
			return array(
				'value'  => 'hub',
				'reason' => __( 'This page is close to the front page and links to several other pages from its content.', 'seo-studio' ),
			);
		}
		if ( null !== $depth && $depth >= 3 ) {
			return array(
				'value'  => 'supporting',
				'reason' => __( 'This page is several clicks from the front page.', 'seo-studio' ),
			);
		}

		return array(
			'value'  => 'unassigned',
			'reason' => __( 'The link graph does not show a hub or a deep supporting page.', 'seo-studio' ),
		);
	}

	/**
	 * Repeat the closest referring page's topic when one was supplied.
	 *
	 * @return array{value: string, reason: string}
	 */
	private function parentTopic( string $topic ): array {
		if ( '' === $topic ) {
			return array(
				'value'  => '',
				'reason' => __( 'No contextual referring page has a topic yet.', 'seo-studio' ),
			);
		}

		return array(
			'value'  => $topic,
			'reason' => __( 'Suggested from the closest contextual referring page.', 'seo-studio' ),
		);
	}

	/**
	 * Keep up to five related phrases that are not the primary topic.
	 *
	 * @param array<string, mixed> $page
	 * @return array{value: string[], reason: string}
	 */
	private function entities( array $page, string $primary ): array {
		$seen   = array();
		$values = array();
		$skip   = mb_strtolower( $primary, 'UTF-8' );
		foreach ( array( 'related', 'synonyms' ) as $key ) {
			foreach ( (array) ( $page[ $key ] ?? array() ) as $phrase ) {
				$text = trim( (string) $phrase );
				$fold = mb_strtolower( $text, 'UTF-8' );
				if ( '' === $text || $fold === $skip || isset( $seen[ $fold ] ) ) {
					continue;
				}
				$seen[ $fold ] = true;
				$values[]      = $text;
				if ( count( $values ) >= 5 ) {
					break 2;
				}
			}
		}
		if ( array() === $values ) {
			return array(
				'value'  => array(),
				'reason' => __( 'No related phrases or synonyms are saved.', 'seo-studio' ),
			);
		}

		return array(
			'value'  => $values,
			'reason' => __( 'Taken from the related phrases and synonyms already saved on this page.', 'seo-studio' ),
		);
	}

	/**
	 * Split wording into comparable words.
	 *
	 * @return string[]
	 */
	private function words( string $text ): array {
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $text, 'UTF-8' ) );
		if ( ! is_array( $parts ) ) {
			return array();
		}
		return array_values( array_filter( $parts, static fn( string $word ): bool => '' !== $word ) );
	}
}
