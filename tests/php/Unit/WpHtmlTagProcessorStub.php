<?php
/**
 * Minimal tag processor so unit tests cover hidden-content marking.
 *
 * @package SEOStudio
 */

declare(strict_types=1);

if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
	return;
}

/**
 * Reads and writes attributes on the next HTML tag.
 */
final class WP_HTML_Tag_Processor {
	private string $html;
	private int $cursor = 0;
	private int $tag_end = 0;
	private string $attributes = '';

	public function __construct( string $html ) {
		$this->html = $html;
	}

	public function next_tag(): bool {
		$found = preg_match( '/<[a-zA-Z][a-zA-Z0-9:-]*\b([^>]*)>/', $this->html, $matches, PREG_OFFSET_CAPTURE, $this->cursor );
		if ( 1 !== $found ) {
			return false;
		}

		$this->attributes = (string) $matches[1][0];
		$this->tag_end    = (int) $matches[0][1] + strlen( (string) $matches[0][0] );
		$this->cursor     = $this->tag_end;
		return true;
	}

	public function get_attribute( string $name ): ?string {
		$pattern = '/(?:^|\s)' . preg_quote( $name, '/' ) . '(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?(?=\s|$)/i';
		if ( 1 !== preg_match( $pattern, $this->attributes, $matches ) ) {
			return null;
		}

		return $matches[1] ?? $matches[2] ?? $matches[3] ?? '';
	}

	public function set_attribute( string $name, string $value ): void {
		$insert           = ' ' . $name . '="' . $value . '"';
		$this->html       = substr( $this->html, 0, $this->tag_end - 1 ) . $insert . substr( $this->html, $this->tag_end - 1 );
		$this->tag_end   += strlen( $insert );
		$this->cursor     = $this->tag_end;
		$this->attributes .= $insert;
	}

	public function get_updated_html(): string {
		return $this->html;
	}
}
