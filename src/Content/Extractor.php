<?php
/**
 * Turns HTML into a PageModel.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

namespace SEOStudio\Content;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Extracts the fields both scoring engines read.
 */
final class Extractor {
	/**
	 * Build a page model from HTML.
	 *
	 * @param array<string, mixed> $context
	 */
	public function extract( string $html, array $context = array() ): PageModel {
		$render_mode = (string) ( $context['render_mode'] ?? 'content' );
		$document    = $this->load( $this->markHidden( $html ) );
		$this->removeHidden( $document );

		$selector = (string) ( $context['content_selector'] ?? '' );
		$root     = $this->selectRoot( $document, $render_mode, $selector );
		$body     = $this->textOf( $root['node'] );
		$url      = (string) ( $context['url'] ?? '' );
		$title    = array_key_exists( 'title_tag', $context )
			? (string) $context['title_tag']
			: $this->textOfFirst( $document, '//title' );
		$meta     = array_key_exists( 'meta_description', $context )
			? (string) $context['meta_description']
			: $this->metaContent( $document, 'description' );

		$headings = $this->headings( $root['node'] );
		$h1       = array();
		foreach ( $headings as $heading ) {
			if ( 1 === $heading['level'] ) {
				$h1[] = $heading['text'];
			}
		}

		$live      = 'live' === $render_mode;
		$path      = Urls::component( $url, PHP_URL_PATH );
		$pieces    = preg_split( '/\s+/', trim( $body ) );
		$lang_attr = $document->documentElement?->getAttribute( 'lang' );

		return PageModel::fromArray(
			array(
				'url'                   => $url,
				'slug'                  => trim( is_string( $path ) ? $path : '', '/' ),
				'title_tag'             => $title,
				'meta_description'      => $meta,
				'canonical'             => array_key_exists( 'canonical', $context )
					? (string) $context['canonical']
					: $this->attributeOfFirst( $document, '//link[contains(@rel, "canonical")]', 'href' ),
				'robots'                => trim(
					( array_key_exists( 'robots', $context )
					? (string) $context['robots']
					: $this->metaContent( $document, 'robots' ) ) . ', ' . (string) ( $context['http_robots'] ?? '' ),
					', '
				),
				'h1'                    => $h1,
				'headings'              => $headings,
				'body_text'             => $body,
				'word_count'            => '' === trim( $body ) ? 0 : count( is_array( $pieces ) ? $pieces : array() ),
				'paragraphs'            => $this->paragraphs( $root['node'] ),
				'sentences'             => $this->sentences( $body ),
				'links'                 => $this->links( $root['node'], $url ),
				'images'                => $this->images( $document, $root['node'] ),
				'has_external_css'      => $live ? $this->hasStylesheet( $document ) : null,
				'html_bytes'            => $live ? strlen( $html ) : null,
				'visible_text_bytes'    => $live ? strlen( $this->textOf( $document->documentElement ?? $document ) ) : null,
				'is_https'              => array_key_exists( 'is_https', $context )
					? (bool) $context['is_https']
					: str_starts_with( strtolower( $url ), 'https://' ),
				'lang'                  => (string) ( $context['lang'] ?? ( is_string( $lang_attr ) && '' !== $lang_attr ? $lang_attr : 'en' ) ),
				'metadata_status'       => (string) ( $context['metadata_status'] ?? ( 'live' === $render_mode ? 'observed_html' : 'estimated' ) ),
				'render_mode'           => $render_mode,
				'content_selector_used' => $root['selector'],
				'rendered_at'           => gmdate( 'c' ),
				'page_type'             => (string) ( $context['page_type'] ?? '' ),
			)
		);
	}

	private function load( string $html ): DOMDocument {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return $document;
	}

	private function markHidden( string $html ): string {
		if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			$aria  = $processor->get_attribute( 'aria-hidden' );
			$style = $processor->get_attribute( 'style' );
			$hide  = null !== $processor->get_attribute( 'hidden' )
				|| ( is_string( $aria ) && 'true' === strtolower( $aria ) )
				|| $this->styleHides( is_string( $style ) ? $style : '' );
			if ( $hide ) {
				$processor->set_attribute( 'data-seostudio-hidden', '1' );
			}
		}

		return $processor->get_updated_html();
	}

	private function removeHidden( DOMDocument $document ): void {
		$xpath  = new DOMXPath( $document );
		$nodes  = $xpath->query( '//script|//style|//noscript|//template|//svg|//iframe' );
		$remove = array();
		if ( false !== $nodes ) {
			foreach ( $nodes as $node ) {
				$remove[] = $node;
			}
		}
		foreach ( $document->getElementsByTagName( '*' ) as $element ) {
			if ( $element instanceof DOMElement && $this->elementIsHidden( $element ) ) {
				$remove[] = $element;
			}
		}
		foreach ( $remove as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
	}

	private function elementIsHidden( DOMElement $element ): bool {
		if ( $element->hasAttribute( 'hidden' ) || $element->hasAttribute( 'data-seostudio-hidden' ) ) {
			return true;
		}

		if ( 'true' === strtolower( $element->getAttribute( 'aria-hidden' ) ) ) {
			return true;
		}

		return $this->styleHides( $element->getAttribute( 'style' ) );
	}

	private function styleHides( string $style ): bool {
		$declarations = preg_split( '/;/', strtolower( $style ) );
		if ( ! is_array( $declarations ) ) {
			return false;
		}

		foreach ( $declarations as $declaration ) {
			$parts = explode( ':', $declaration, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			$property = trim( $parts[0] );
			$value    = trim( $parts[1] );
			if ( ( 'display' === $property && 'none' === $value ) || ( 'visibility' === $property && 'hidden' === $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Select root.
	 *
	 * @return array{node: DOMNode, selector: string}
	 */
	private function selectRoot( DOMDocument $document, string $renderMode, string $selector ): array {
		if ( '' !== $selector ) {
			$found = $this->findSelector( $document, $selector );
			if ( $found instanceof DOMElement ) {
				return array(
					'node'     => $found,
					'selector' => $selector,
				);
			}
		}

		foreach ( array( 'main', 'article' ) as $tag ) {
			$node = $document->getElementsByTagName( $tag )->item( 0 );
			if ( $node instanceof DOMElement ) {
				return array(
					'node'     => $node,
					'selector' => $tag,
				);
			}
		}

		$role = ( new DOMXPath( $document ) )->query( '//*[@role="main"]' );
		if ( false !== $role && $role->item( 0 ) instanceof DOMElement ) {
			return array(
				'node'     => $role->item( 0 ),
				'selector' => '[role=main]',
			);
		}

		if ( 'content' === $renderMode ) {
			return array(
				'node'     => $document->getElementsByTagName( 'body' )->item( 0 ) ?? $document,
				'selector' => 'content',
			);
		}

		$dense = $this->densestBlock( $document );
		return array(
			'node'     => $dense ?? ( $document->getElementsByTagName( 'body' )->item( 0 ) ?? $document ),
			'selector' => $dense instanceof DOMElement ? 'density' : 'body',
		);
	}

	private function findSelector( DOMDocument $document, string $selector ): ?DOMElement {
		if ( str_starts_with( $selector, '#' ) ) {
			$node = $document->getElementById( substr( $selector, 1 ) );
			return $node instanceof DOMElement ? $node : null;
		}

		if ( str_starts_with( $selector, '.' ) ) {
			$class = substr( $selector, 1 );
			foreach ( $document->getElementsByTagName( '*' ) as $element ) {
					$classes = preg_split( '/\s+/', $element->getAttribute( 'class' ) );
				if ( $element instanceof DOMElement && in_array( $class, is_array( $classes ) ? $classes : array(), true ) ) {
					return $element;
				}
			}
			return null;
		}

		if ( '[role=main]' === $selector ) {
			$role = ( new DOMXPath( $document ) )->query( '//*[@role="main"]' );
			$node = false !== $role ? $role->item( 0 ) : null;
			return $node instanceof DOMElement ? $node : null;
		}

		if ( 1 === preg_match( '/^[a-z0-9]+$/', $selector ) ) {
			$node = $document->getElementsByTagName( $selector )->item( 0 );
			return $node instanceof DOMElement ? $node : null;
		}

		return null;
	}

	private function densestBlock( DOMDocument $document ): ?DOMElement {
		$best   = null;
		$score  = -1.0;
		$length = 0;
		foreach ( array( 'div', 'section' ) as $tag ) {
			foreach ( $document->getElementsByTagName( $tag ) as $element ) {
				if ( ! $element instanceof DOMElement || $this->isBoilerplate( $element ) ) {
					continue;
				}
				$text = strlen( $this->textOf( $element ) );
				if ( $text < 40 ) {
					continue;
				}
				$saved   = $document->saveHTML( $element );
				$html    = strlen( is_string( $saved ) ? $saved : '' );
				$density = $text / max( 1, $html );
				if ( $density > $score || ( $density === $score && $text > $length ) ) {
					$best   = $element;
					$score  = $density;
					$length = $text;
				}
			}
		}

		return $best;
	}

	private function isBoilerplate( DOMElement $element ): bool {
		$node = $element;
		while ( $node instanceof DOMElement ) {
			$tag = strtolower( $node->tagName );
			if ( in_array( $tag, array( 'header', 'footer', 'nav', 'aside' ), true ) ) {
				return true;
			}
			$class = strtolower( $node->getAttribute( 'class' ) . ' ' . $node->getAttribute( 'id' ) );
			foreach ( array( 'cookie', 'menu', 'widget', 'sidebar', 'popup', 'consent', 'banner' ) as $needle ) {
				if ( str_contains( $class, $needle ) ) {
					return true;
				}
			}
			$parent = $node->parentNode;
			$node   = $parent instanceof DOMElement ? $parent : null;
		}

		return false;
	}

	/**
	 * Collect heading elements under the content root.
	 *
	 * @return array<int, array{level: int, text: string, order: int}>
	 */
	private function headings( DOMNode $root ): array {
		$headings = array();
		$order    = 0;
		$this->walk(
			$root,
			function ( DOMNode $node ) use ( &$headings, &$order ): void {
				if ( ! $node instanceof DOMElement ) {
					return;
				}
				$tag = strtolower( $node->tagName );
				if ( 1 === preg_match( '/^h([1-6])$/', $tag, $matches ) ) {
					$headings[] = array(
						'level' => (int) $matches[1],
						'text'  => $this->textOf( $node ),
						'order' => $order,
					);
					++$order;
				}
			}
		);
		return $headings;
	}

	/**
	 * Collect paragraph text under the content root.
	 *
	 * @return string[]
	 */
	private function paragraphs( DOMNode $root ): array {
		$paragraphs = array();
		if ( ! $root instanceof DOMElement && ! $root instanceof DOMDocument ) {
			return $paragraphs;
		}
		$elements = $root instanceof DOMDocument
			? $root->getElementsByTagName( 'p' )
			: $root->getElementsByTagName( 'p' );
		foreach ( $elements as $paragraph ) {
			$text = $this->textOf( $paragraph );
			if ( '' !== $text ) {
				$paragraphs[] = $text;
			}
		}
		return $paragraphs;
	}

	/**
	 * Split body text into sentences.
	 *
	 * @return string[]
	 */
	private function sentences( string $text ): array {
		$parts = preg_split( '/(?<=[.!?])\s+/u', trim( $text ) );
		if ( ! is_array( $parts ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'trim', $parts ), static fn( string $part ): bool => '' !== $part ) );
	}

	/**
	 * Collect links under the content root.
	 *
	 * @return array<int, array{href: string, anchor: string, internal: bool, nofollow: bool}>
	 */
	private function links( DOMNode $root, string $pageUrl ): array {
		$links     = array();
		$host_part = Urls::component( $pageUrl, PHP_URL_HOST );
		$host      = is_string( $host_part ) ? $host_part : '';
		$this->walk(
			$root,
			function ( DOMNode $node ) use ( &$links, $host ): void {
				if ( ! $node instanceof DOMElement || 'a' !== strtolower( $node->tagName ) ) {
					return;
				}
				$href = $node->getAttribute( 'href' );
				if ( '' === $href ) {
					return;
				}
				$link_part = Urls::component( $href, PHP_URL_HOST );
				$link_host = is_string( $link_part ) ? $link_part : '';
				$rel       = strtolower( $node->getAttribute( 'rel' ) );
				$links[]   = array(
					'href'     => $href,
					'anchor'   => $this->textOf( $node ),
					'internal' => '' === $link_host || ( '' !== $host && 0 === strcasecmp( $link_host, $host ) ),
					'nofollow' => str_contains( $rel, 'nofollow' ),
				);
			}
		);
		return $links;
	}

	/**
	 * Collect images from the document.
	 *
	 * @return array<int, array{src: string, alt: string, in_body: bool}>
	 */
	private function images( DOMDocument $document, DOMNode $root ): array {
		$images = array();
		foreach ( $document->getElementsByTagName( 'img' ) as $image ) {
			if ( ! $image instanceof DOMElement ) {
				continue;
			}
			$images[] = array(
				'src'     => $image->getAttribute( 'src' ),
				'alt'     => $image->getAttribute( 'alt' ),
				'in_body' => $this->contains( $root, $image ),
			);
		}
		return $images;
	}

	private function hasStylesheet( DOMDocument $document ): bool {
		foreach ( $document->getElementsByTagName( 'link' ) as $link ) {
			if ( $link instanceof DOMElement && str_contains( strtolower( $link->getAttribute( 'rel' ) ), 'stylesheet' ) ) {
				return true;
			}
		}
		return false;
	}

	private function textOf( DOMNode $node ): string {
		$parts = array();
		$this->walk(
			$node,
			function ( DOMNode $current ) use ( &$parts ): void {
				if ( XML_TEXT_NODE === $current->nodeType ) {
					$parts[] = $current->nodeValue ?? '';
				}
			}
		);
		$text = preg_replace( '/\s+/u', ' ', implode( ' ', $parts ) );
		return trim( is_string( $text ) ? $text : '' );
	}

	private function textOfFirst( DOMDocument $document, string $query ): string {
		$nodes = ( new DOMXPath( $document ) )->query( $query );
		$node  = false !== $nodes ? $nodes->item( 0 ) : null;
		return $node instanceof DOMNode ? $this->textOf( $node ) : '';
	}

	private function metaContent( DOMDocument $document, string $name ): string {
		foreach ( $document->getElementsByTagName( 'meta' ) as $meta ) {
			if ( $meta instanceof DOMElement && 0 === strcasecmp( $meta->getAttribute( 'name' ), $name ) ) {
				return $meta->getAttribute( 'content' );
			}
		}
		return '';
	}

	private function attributeOfFirst( DOMDocument $document, string $query, string $attribute ): string {
		$nodes = ( new DOMXPath( $document ) )->query( $query );
		$node  = false !== $nodes ? $nodes->item( 0 ) : null;
		return $node instanceof DOMElement ? $node->getAttribute( $attribute ) : '';
	}

	private function contains( DOMNode $root, DOMNode $needle ): bool {
		$node = $needle;
		while ( $node instanceof DOMNode ) {
			if ( $node->isSameNode( $root ) ) {
				return true;
			}
			$parent = $node->parentNode;
			$node   = $parent instanceof DOMNode ? $parent : null;
		}
		return false;
	}

	/**
	 * Visit each node under the content root.
	 *
	 * @param callable(DOMNode): void $visitor
	 */
	private function walk( DOMNode $node, callable $visitor ): void {
		$visitor( $node );
		foreach ( $node->childNodes as $child ) {
			$this->walk( $child, $visitor );
		}
	}
}
