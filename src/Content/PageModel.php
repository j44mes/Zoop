<?php
/**
 * Normalised page passed to both scoring engines.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Content;

/**
 * Immutable extraction of one analysable page.
 */
final class PageModel {
	/**
	 * __construct.
	 *
	 * @param string[]                                                              $h1
	 * @param string[]                                                              $documentH1
	 * @param array<int, array{level: int, text: string, order: int}>             $headings
	 * @param string[]                                                              $paragraphs
	 * @param string[]                                                              $sentences
	 * @param array<int, array{href: string, anchor: string, internal: bool, nofollow: bool, kind?: string}> $links
	 * @param array<int, array{src: string, alt: string, in_body: bool, kind?: string, source?: string}> $images
	 */
	public function __construct(
		public readonly string $url = '',
		public readonly string $slug = '',
		public readonly string $titleTag = '',
		public readonly string $metaDescription = '',
		public readonly string $canonical = '',
		public readonly string $robots = '',
		public readonly array $h1 = array(),
		public readonly array $documentH1 = array(),
		public readonly string $documentH1Evidence = 'not_observed',
		public readonly array $headings = array(),
		public readonly string $bodyText = '',
		public readonly int $wordCount = 0,
		public readonly array $paragraphs = array(),
		public readonly array $sentences = array(),
		public readonly array $links = array(),
		public readonly array $images = array(),
		public readonly ?bool $hasExternalCss = null,
		public readonly ?int $htmlBytes = null,
		public readonly ?int $visibleTextBytes = null,
		public readonly bool $isHttps = false,
		public readonly string $lang = 'en',
		public readonly string $renderMode = 'content',
		public readonly string $contentSelectorUsed = '',
		public readonly string $renderedAt = '',
		public readonly string $pageType = '',
		public readonly string $metadataStatus = 'estimated',
		public readonly string $titleEvidence = 'observed',
		public readonly string $descriptionEvidence = 'observed'
	) {}

	/**
	 * Code checks need a full-page fetch. Null measurements mean they are not yet checked.
	 */
	public function codeChecksAvailable(): bool {
		return null !== $this->hasExternalCss
			&& null !== $this->htmlBytes
			&& null !== $this->visibleTextBytes
			&& $this->htmlBytes > 0;
	}

	/**
	 * Keep code measurements from an earlier live fetch when this render has none.
	 */
	public function retainCodeMeasurements( ?self $previous ): self {
		if ( $this->codeChecksAvailable() || null === $previous || ! $previous->codeChecksAvailable() ) {
			return $this;
		}

		return new self(
			url: $this->url,
			slug: $this->slug,
			titleTag: $this->titleTag,
			metaDescription: $this->metaDescription,
			canonical: $this->canonical,
			robots: $this->robots,
			h1: $this->h1,
			documentH1: $this->documentH1,
			documentH1Evidence: $this->documentH1Evidence,
			headings: $this->headings,
			bodyText: $this->bodyText,
			wordCount: $this->wordCount,
			paragraphs: $this->paragraphs,
			sentences: $this->sentences,
			links: $this->links,
			images: $this->images,
			hasExternalCss: $previous->hasExternalCss,
			htmlBytes: $previous->htmlBytes,
			visibleTextBytes: $previous->visibleTextBytes,
			isHttps: $this->isHttps,
			lang: $this->lang,
			renderMode: $this->renderMode,
			contentSelectorUsed: $this->contentSelectorUsed,
			renderedAt: $this->renderedAt,
			pageType: $this->pageType,
			metadataStatus: $this->metadataStatus,
			titleEvidence: $this->titleEvidence,
			descriptionEvidence: $this->descriptionEvidence
		);
	}

	/**
	 * From array.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function fromArray( array $data ): self {
		$headings = array();
		foreach ( (array) ( $data['headings'] ?? array() ) as $index => $heading ) {
			if ( ! is_array( $heading ) ) {
				continue;
			}
			$headings[] = array(
				'level' => (int) ( $heading['level'] ?? 0 ),
				'text'  => (string) ( $heading['text'] ?? '' ),
				'order' => (int) ( $heading['order'] ?? $index ),
			);
		}

		$images = array();
		foreach ( (array) ( $data['images'] ?? array() ) as $image ) {
			if ( ! is_array( $image ) ) {
				continue;
			}
			$in_body = (bool) ( $image['in_body'] ?? false );
			$kind    = (string) ( $image['kind'] ?? '' );
			if ( ! in_array( $kind, array( 'html', 'template', 'css_background', 'unverified' ), true ) ) {
				$kind = $in_body ? 'html' : 'template';
			}
			$images[] = array(
				'src'     => (string) ( $image['src'] ?? '' ),
				'alt'     => (string) ( $image['alt'] ?? '' ),
				'in_body' => $in_body,
				'kind'    => $kind,
				'source'  => (string) ( $image['source'] ?? '' ),
			);
		}

		$links = array();
		foreach ( (array) ( $data['links'] ?? array() ) as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			$kind = (string) ( $link['kind'] ?? '' );
			if ( ! in_array( $kind, array( 'contextual', 'template' ), true ) ) {
				// Older stored rows only contained content-root links.
				$kind = 'contextual';
			}
			$links[] = array(
				'href'     => (string) ( $link['href'] ?? '' ),
				'anchor'   => (string) ( $link['anchor'] ?? '' ),
				'internal' => (bool) ( $link['internal'] ?? false ),
				'nofollow' => (bool) ( $link['nofollow'] ?? false ),
				'kind'     => $kind,
			);
		}

		$h1 = array();
		foreach ( (array) ( $data['h1'] ?? array() ) as $text ) {
			$h1[] = (string) $text;
		}

		$document_h1       = array();
		$document_evidence = MetadataEvidence::known( isset( $data['document_h1_evidence'] ) ? (string) $data['document_h1_evidence'] : null, MetadataEvidence::NOT_OBSERVED );
		if ( MetadataEvidence::OBSERVED === $document_evidence && isset( $data['document_h1'] ) && is_array( $data['document_h1'] ) ) {
			foreach ( $data['document_h1'] as $text ) {
				$document_h1[] = (string) $text;
			}
		} else {
			$document_evidence = MetadataEvidence::NOT_OBSERVED;
			$document_h1       = array();
		}

		$has_css = $data['has_external_css'] ?? null;
		$html    = $data['html_bytes'] ?? null;
		$visible = $data['visible_text_bytes'] ?? null;

		return new self(
			url: (string) ( $data['url'] ?? '' ),
			slug: (string) ( $data['slug'] ?? '' ),
			titleTag: (string) ( $data['title_tag'] ?? '' ),
			metaDescription: (string) ( $data['meta_description'] ?? '' ),
			canonical: (string) ( $data['canonical'] ?? '' ),
			robots: (string) ( $data['robots'] ?? '' ),
			h1: $h1,
			documentH1: $document_h1,
			documentH1Evidence: $document_evidence,
			headings: $headings,
			bodyText: (string) ( $data['body_text'] ?? '' ),
			wordCount: (int) ( $data['word_count'] ?? 0 ),
			paragraphs: array_map( 'strval', (array) ( $data['paragraphs'] ?? array() ) ),
			sentences: array_map( 'strval', (array) ( $data['sentences'] ?? array() ) ),
			links: $links,
			images: $images,
			hasExternalCss: null === $has_css ? null : (bool) $has_css,
			htmlBytes: null === $html ? null : (int) $html,
			visibleTextBytes: null === $visible ? null : (int) $visible,
			isHttps: (bool) ( $data['is_https'] ?? false ),
			lang: (string) ( $data['lang'] ?? 'en' ),
			renderMode: (string) ( $data['render_mode'] ?? 'content' ),
			contentSelectorUsed: (string) ( $data['content_selector_used'] ?? '' ),
			renderedAt: (string) ( $data['rendered_at'] ?? '' ),
			pageType: (string) ( $data['page_type'] ?? '' ),
			metadataStatus: (string) ( $data['metadata_status'] ?? 'estimated' ),
			titleEvidence: MetadataEvidence::known( array_key_exists( 'title_evidence', $data ) ? (string) $data['title_evidence'] : null, MetadataEvidence::OBSERVED ),
			descriptionEvidence: MetadataEvidence::known( array_key_exists( 'description_evidence', $data ) ? (string) $data['description_evidence'] : null, MetadataEvidence::OBSERVED )
		);
	}

	/**
	 * Serialise every field. The keys match fromArray().
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'url'                   => $this->url,
			'slug'                  => $this->slug,
			'title_tag'             => $this->titleTag,
			'meta_description'      => $this->metaDescription,
			'canonical'             => $this->canonical,
			'robots'                => $this->robots,
			'h1'                    => $this->h1,
			'document_h1'           => $this->documentH1,
			'document_h1_evidence'  => $this->documentH1Evidence,
			'headings'              => $this->headings,
			'body_text'             => $this->bodyText,
			'word_count'            => $this->wordCount,
			'paragraphs'            => $this->paragraphs,
			'sentences'             => $this->sentences,
			'links'                 => $this->links,
			'images'                => $this->images,
			'has_external_css'      => $this->hasExternalCss,
			'html_bytes'            => $this->htmlBytes,
			'visible_text_bytes'    => $this->visibleTextBytes,
			'is_https'              => $this->isHttps,
			'lang'                  => $this->lang,
			'render_mode'           => $this->renderMode,
			'metadata_status'       => $this->metadataStatus,
			'content_selector_used' => $this->contentSelectorUsed,
			'rendered_at'           => $this->renderedAt,
			'page_type'             => $this->pageType,
			'title_evidence'        => $this->titleEvidence,
			'description_evidence'  => $this->descriptionEvidence,
		);
	}

	/**
	 * Hash of the scored inputs. The timestamp is excluded so a repeat render can match.
	 */
	public function inputHash(): string {
		$data = $this->toArray();
		unset( $data['rendered_at'] );
		if ( function_exists( 'wp_json_encode' ) ) {
			$encoded = wp_json_encode( $data );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- The unit suite does not load WordPress.
			$encoded = json_encode( $data );
		}

		return md5( is_string( $encoded ) ? $encoded : '' );
	}

	public function firstH1(): string {
		return $this->h1[0] ?? '';
	}

	/**
	 * First H1 observed in the full document. Empty unless document H1 evidence is observed.
	 */
	public function firstDocumentH1(): string {
		return $this->documentH1[0] ?? '';
	}

	/**
	 * Return headings from level 2 to level 6.
	 *
	 * @return array<int, array{level: int, text: string, order: int}>
	 */
	public function subheadings(): array {
		return array_values(
			array_filter(
				$this->headings,
				static function ( array $heading ): bool {
					return $heading['level'] >= 2 && $heading['level'] <= 6;
				}
			)
		);
	}

	public function path(): string {
		$parsed = Urls::component( $this->url, PHP_URL_PATH );
		$path   = is_string( $parsed ) ? $parsed : '';
		return '' === $path ? '/' : $path;
	}
}
