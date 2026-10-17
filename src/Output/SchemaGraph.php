<?php
/**
 * JSON-LD nodes for Owner mode.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Output;

/**
 * Pure graph builder. LocalBusiness, VideoObject, and NewsArticle are the V2 types.
 */
final class SchemaGraph {
	/**
	 * Build the nodes for one response.
	 *
	 * @param array<string, mixed> $input
	 * @return array<int, array<string, mixed>>
	 */
	public function nodes( array $input ): array {
		$nodes = array();
		if ( ! empty( $input['home'] ) ) {
			$nodes[] = $this->compact(
				array(
					'@type' => 'WebSite',
					'@id'   => (string) ( $input['site_url'] ?? '' ) . '#website',
					'url'   => (string) ( $input['site_url'] ?? '' ),
					'name'  => (string) ( $input['site_name'] ?? '' ),
				)
			);
			$nodes[] = $this->compact(
				array(
					'@type' => 'Organization',
					'@id'   => (string) ( $input['site_url'] ?? '' ) . '#organization',
					'name'  => (string) ( $input['site_name'] ?? '' ),
					'url'   => (string) ( $input['site_url'] ?? '' ),
				)
			);
			$local   = $this->localBusiness( $input );
			if ( null !== $local ) {
				$nodes[] = $local;
			}
			return $nodes;
		}

		$news = ! empty( $input['is_news'] );
		$type = ( 'post' === (string) ( $input['kind'] ?? 'post' ) ) ? ( $news ? 'NewsArticle' : 'Article' ) : 'WebPage';
		$node = array(
			'@type'            => $type,
			'@id'              => (string) ( $input['url'] ?? '' ) . '#primary',
			'headline'         => (string) ( $input['title'] ?? '' ),
			'name'             => (string) ( $input['title'] ?? '' ),
			'description'      => (string) ( $input['description'] ?? '' ),
			'url'              => (string) ( $input['url'] ?? '' ),
			'mainEntityOfPage' => (string) ( $input['url'] ?? '' ),
			'datePublished'    => (string) ( $input['date_published'] ?? '' ),
			'dateModified'     => (string) ( $input['date_modified'] ?? '' ),
		);
		if ( '' !== (string) ( $input['author'] ?? '' ) ) {
			$node['author'] = array(
				'@type' => 'Person',
				'name'  => (string) $input['author'],
			);
		}
		if ( '' !== (string) ( $input['image'] ?? '' ) ) {
			$node['image'] = (string) $input['image'];
		}
		$nodes[] = $this->compact( $node );
		$nodes[] = $this->breadcrumbs( $input );
		$video   = $this->video( $input );
		if ( null !== $video ) {
			$nodes[] = $video;
		}

		return $nodes;
	}

	/**
	 * Encode a graph with angle brackets escaped so it can sit in a script tag.
	 *
	 * @param array<int, array<string, mixed>> $nodes
	 */
	public function encode( array $nodes ): string {
		$json = json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This class stays free of WordPress so unit tests can build the graph.
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $nodes,
			),
			JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return is_string( $json ) ? $json : '{}';
	}

	/**
	 * LocalBusiness node when the setting is enabled and named.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|null
	 */
	private function localBusiness( array $input ): ?array {
		$local = isset( $input['local'] ) && is_array( $input['local'] ) ? $input['local'] : array();
		if ( empty( $local['enabled'] ) || '' === trim( (string) ( $local['name'] ?? '' ) ) ) {
			return null;
		}

		return $this->compact(
			array(
				'@type'     => 'LocalBusiness',
				'@id'       => (string) ( $input['site_url'] ?? '' ) . '#local',
				'name'      => (string) $local['name'],
				'telephone' => (string) ( $local['telephone'] ?? '' ),
				'address'   => array(
					'@type'           => 'PostalAddress',
					'streetAddress'   => (string) ( $local['street'] ?? '' ),
					'addressLocality' => (string) ( $local['city'] ?? '' ),
				),
			)
		);
	}

	/**
	 * BreadcrumbList for the home page and this URL.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>|null
	 */
	private function video( array $input ): ?array {
		$url = trim( (string) ( $input['video_url'] ?? '' ) );
		if ( '' === $url ) {
			return null;
		}

		return $this->compact(
			array(
				'@type'        => 'VideoObject',
				'@id'          => (string) ( $input['url'] ?? '' ) . '#video',
				'name'         => (string) ( $input['title'] ?? '' ),
				'description'  => (string) ( $input['description'] ?? '' ),
				'contentUrl'   => $url,
				'thumbnailUrl' => (string) ( $input['image'] ?? '' ),
			)
		);
	}

	/**
	 * BreadcrumbList for the home page and this URL.
	 *
	 * @param array<string, mixed> $input
	 * @return array<string, mixed>
	 */
	private function breadcrumbs( array $input ): array {
		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => (string) ( $input['url'] ?? '' ) . '#breadcrumb',
			'itemListElement' => array(
				array(
					'@type'    => 'ListItem',
					'position' => 1,
					'name'     => (string) ( $input['site_name'] ?? '' ),
					'item'     => (string) ( $input['site_url'] ?? '' ),
				),
				array(
					'@type'    => 'ListItem',
					'position' => 2,
					'name'     => (string) ( $input['title'] ?? '' ),
					'item'     => (string) ( $input['url'] ?? '' ),
				),
			),
		);
	}

	/**
	 * Drop empty strings so optional fields stay out of the graph.
	 *
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	private function compact( array $node ): array {
		$clean = array();
		foreach ( $node as $key => $value ) {
			if ( is_string( $value ) && '' === trim( $value ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = $this->compact( $value );
				if ( array() === $value ) {
					continue;
				}
			}
			$clean[ $key ] = $value;
		}

		return $clean;
	}
}
