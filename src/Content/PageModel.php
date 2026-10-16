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
	 * @param array<int, array{level: int, text: string, order: int}>             $headings
	 * @param string[]                                                              $paragraphs
	 * @param string[]                                                              $sentences
	 * @param array<int, array{href: string, anchor: string, internal: bool, nofollow: bool}> $links
	 * @param array<int, array{src: string, alt: string, in_body: bool}>           $images
	 */
	public function __construct(
		public readonly string $url = '',
		public readonly string $slug = '',
		public readonly string $titleTag = '',
		public readonly string $metaDescription = '',
		public readonly string $canonical = '',
		public readonly string $robots = '',
		public readonly array $h1 = array(),
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
		public readonly string $pageType = ''
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
			$images[] = array(
				'src'     => (string) ( $image['src'] ?? '' ),
				'alt'     => (string) ( $image['alt'] ?? '' ),
				'in_body' => (bool) ( $image['in_body'] ?? false ),
			);
		}

		$links = array();
		foreach ( (array) ( $data['links'] ?? array() ) as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			$links[] = array(
				'href'     => (string) ( $link['href'] ?? '' ),
				'anchor'   => (string) ( $link['anchor'] ?? '' ),
				'internal' => (bool) ( $link['internal'] ?? false ),
				'nofollow' => (bool) ( $link['nofollow'] ?? false ),
			);
		}

		$h1 = array();
		foreach ( (array) ( $data['h1'] ?? array() ) as $text ) {
			$h1[] = (string) $text;
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
			pageType: (string) ( $data['page_type'] ?? '' )
		);
	}

	public function firstH1(): string {
		return $this->h1[0] ?? '';
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
